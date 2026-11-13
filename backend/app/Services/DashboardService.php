<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Budget;
use App\Models\SavingsGoal;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Everything the dashboard shows, computed from saved records only.
 * Business figures reuse ProfitReportService, so the dashboard and the profit report always agree.
 */
final class DashboardService
{
    private const PAIR_TYPES = ['transfer', 'owner_withdrawal', 'owner_contribution'];

    public function __construct(
        private readonly ProfitReportService $profit,
        private readonly InventoryReportService $inventory,
        private readonly DebtSummaryService $debts,
        private readonly SavingsService $savings,
        private readonly BudgetService $budgets,
    ) {
    }

    public function build(string $from, string $to): array
    {
        $pnl = $this->profit->profitAndLoss($from, $to);
        $debtSummary = $this->debts->summary(30);
        $accounts = Account::query()->withBalance()->where('is_active', true)->orderBy('scope')->orderBy('name')->get();

        $balance = fn ($scope = null, $type = null) => Money::sum(
            $accounts
                ->when($scope, fn ($c) => $c->filter(fn ($a) => $a->scope->value === $scope))
                ->when($type, fn ($c) => $c->filter(fn ($a) => $a->type->value === $type))
                ->pluck('balance')
        );

        $cashTotal = $balance();
        $inventoryValue = $this->inventory->summary(null, null)['totals']['stock_value_at_cost'];
        $receivables = $debtSummary['owed_to_me']['total'];
        $payables = $debtSummary['i_owe']['total'];
        $assets = Money::sum([$cashTotal, $inventoryValue, $receivables]);

        $goalsSaved = Money::sum(
            SavingsGoal::query()->where('status', '!=', 'cancelled')->get()
                ->map(fn ($goal) => $this->savings->figures($goal)['saved'])
        );

        $budgetEvaluations = $this->budgetEvaluations($to);
        $monthly = $budgetEvaluations->filter(fn ($row) => $row['period'] === 'monthly');

        return [
            'period' => ['from' => $from, 'to' => $to],
            'cards' => [
                'cash' => [
                    'total' => $cashTotal,
                    'business' => $balance('business'),
                    'personal' => $balance('personal'),
                    'accounts' => $accounts->map(fn ($a) => [
                        'id' => $a->id,
                        'name' => $a->name,
                        'scope' => $a->scope->value,
                        'type' => $a->type->value,
                        'balance' => $a->balance,
                    ])->values(),
                ],
                'sales_revenue' => $pnl['sales']['net_sales'],
                'gross_profit' => $pnl['gross_profit'],
                'net_profit' => $pnl['net_profit'],
                'personal_income' => $this->total('personal', 'income', $from, $to),
                'personal_expenses' => $this->total('personal', 'expense', $from, $to),
                'business_expenses' => $this->total('business', 'expense', $from, $to),
                'personal_savings' => [
                    'accounts_total' => $balance('personal', 'savings'),
                    'goals_saved' => $goalsSaved,
                ],
                'owed_to_me' => $receivables,
                'i_owe' => $payables,
                'budget_usage' => [
                    'count' => $monthly->count(),
                    'over' => $monthly->where('status', 'over')->count(),
                    'warning' => $monthly->where('status', 'warning')->count(),
                    'highest' => $monthly->sortByDesc(fn ($row) => (float) $row['percent_used'])->first(),
                ],
                'net_position' => [
                    'assets' => [
                        'cash' => $cashTotal,
                        'inventory_at_cost' => $inventoryValue,
                        'receivables' => $receivables,
                        'total' => $assets,
                    ],
                    'liabilities' => ['payables' => $payables, 'total' => $payables],
                    'net' => Money::sub($assets, $payables),
                ],
            ],
            'charts' => [
                'monthly' => $this->monthly($from, $to),
                'personal_expense_categories' => $this->categoryTotals('personal', $from, $to),
                'business_expense_categories' => $this->categoryTotals('business', $from, $to),
                'cash_flow' => $this->cashFlow($from, $to),
                'budget_vs_actual' => $budgetEvaluations->values(),
            ],
            'notes' => [
                'Account balances are opening balances plus recorded transactions, for active accounts. Selected accounts are not the same as net financial position.',
                'Net financial position = cash in accounts + inventory at cost + money owed to you - money you owe. Business and personal money are both included.',
                'Sales revenue, gross profit and net profit are for the chosen period and come from the profit and loss report. Customer payments and stock purchases change cash, not profit.',
                'Personal income and expenses exclude transfers, owner withdrawals, loans and savings. Business sales are never counted as personal income.',
                'Budget usage covers monthly budgets for the month that contains the end date.',
            ],
        ];
    }

    private function total(string $scope, string $type, string $from, string $to): string
    {
        return Money::fromDatabase(
            DB::table('transactions')->where('user_id', Auth::id())->where('status', 'posted')
                ->where('scope', $scope)->where('type', $type)
                ->whereBetween('transaction_date', [$from, $to])->sum('amount')
        );
    }

    /** Last 12 months at most. Business figures come from the profit and loss report. */
    private function monthly(string $from, string $to): array
    {
        $first = Carbon::parse($from)->startOfMonth();
        $last = Carbon::parse($to)->startOfMonth();

        if ($first->diffInMonths($last) > 11) {
            $first = $last->copy()->subMonthsNoOverflow(11);
        }

        $grouped = DB::table('transactions')
            ->where('user_id', Auth::id())->where('status', 'posted')->whereIn('type', ['income', 'expense'])
            ->whereBetween('transaction_date', [$first->toDateString(), $last->copy()->endOfMonth()->toDateString()])
            ->selectRaw("DATE_FORMAT(transaction_date, '%Y-%m') as month, scope, type, SUM(amount) as total")
            ->groupBy('month', 'scope', 'type')
            ->get();

        $lookup = [];

        foreach ($grouped as $row) {
            $lookup[$row->month][$row->scope][$row->type] = Money::fromDatabase($row->total);
        }

        $rows = [];

        for ($cursor = $first->copy(); $cursor->lte($last); $cursor->addMonthNoOverflow()) {
            $key = $cursor->format('Y-m');
            $pnl = $this->profit->profitAndLoss($cursor->toDateString(), $cursor->copy()->endOfMonth()->toDateString());

            $rows[] = [
                'month' => $key,
                'personal_income' => $lookup[$key]['personal']['income'] ?? '0.00',
                'personal_expenses' => $lookup[$key]['personal']['expense'] ?? '0.00',
                'business_sales' => $pnl['sales']['net_sales'],
                'business_cogs' => $pnl['cost_of_goods_sold']['total'],
                'business_expenses' => $lookup[$key]['business']['expense'] ?? '0.00',
                'business_gross_profit' => $pnl['gross_profit'],
                'business_net_profit' => $pnl['net_profit'],
            ];
        }

        return $rows;
    }

    /** Expenses by top-level category (subcategories roll up into their parent). */
    private function categoryTotals(string $scope, string $from, string $to): array
    {
        $rows = DB::table('transactions as t')
            ->leftJoin('transaction_categories as c', 'c.id', '=', 't.category_id')
            ->leftJoin('transaction_categories as p', 'p.id', '=', 'c.parent_id')
            ->where('t.user_id', Auth::id())->where('t.status', 'posted')->where('t.type', 'expense')
            ->where('t.scope', $scope)->whereBetween('t.transaction_date', [$from, $to])
            ->groupBy(DB::raw('COALESCE(p.id, c.id)'), DB::raw("COALESCE(p.name, c.name, 'Uncategorized')"))
            ->selectRaw("COALESCE(p.id, c.id) as category_id, COALESCE(p.name, c.name, 'Uncategorized') as name, SUM(t.amount) as total")
            ->orderByDesc('total')
            ->get();

        $sum = Money::sum($rows->map(fn ($r) => Money::fromDatabase($r->total)));

        return $rows->map(fn ($r) => [
            'category_id' => $r->category_id !== null ? (int) $r->category_id : null,
            'name' => $r->name,
            'total' => Money::fromDatabase($r->total),
            'percent' => Money::compare($sum, '0') > 0 ? Money::div(Money::mul(Money::fromDatabase($r->total), 100), $sum) : '0.00',
        ])->values()->all();
    }

    /**
     * Real money in and out of all active accounts. Moves between your own accounts are left out,
     * so they never look like income or spending. Daily for up to 62 days, otherwise monthly.
     */
    private function cashFlow(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $daily = $start->diffInDays($end) + 1 <= 62;

        $grouped = DB::table('ledger_entries as le')
            ->join('transactions as t', 't.id', '=', 'le.transaction_id')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->where('le.user_id', Auth::id())->where('t.status', 'posted')->where('a.is_active', true)
            ->whereNotIn('t.type', self::PAIR_TYPES)
            ->whereBetween('le.entry_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw(($daily ? 'le.entry_date' : "DATE_FORMAT(le.entry_date, '%Y-%m')").' as bucket')
            ->selectRaw('COALESCE(SUM(CASE WHEN le.amount > 0 THEN le.amount ELSE 0 END), 0) as money_in')
            ->selectRaw('COALESCE(SUM(CASE WHEN le.amount < 0 THEN -le.amount ELSE 0 END), 0) as money_out')
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $points = [];
        $cursor = $daily ? $start->copy() : $start->copy()->startOfMonth();

        while ($cursor->lte($end)) {
            $label = $daily ? $cursor->toDateString() : $cursor->format('Y-m');
            $bucketEnd = $daily ? $cursor->copy() : $cursor->copy()->endOfMonth()->min($end);
            $row = $grouped->get($label);
            $in = Money::fromDatabase($row?->money_in);
            $out = Money::fromDatabase($row?->money_out);

            $points[] = [
                'label' => $label,
                'money_in' => $in,
                'money_out' => $out,
                'net' => Money::sub($in, $out),
                'closing_balance' => $this->balanceAsOf($bucketEnd->toDateString()),
            ];

            $daily ? $cursor->addDay() : $cursor->addMonthNoOverflow();
        }

        return ['granularity' => $daily ? 'day' : 'month', 'points' => $points];
    }

    /** Total of all active accounts at the end of a date. */
    private function balanceAsOf(string $date): string
    {
        $uid = Auth::id();

        $opening = DB::table('accounts')->where('user_id', $uid)->where('is_active', true)
            ->where('opening_balance_date', '<=', $date)->sum('opening_balance');

        $entries = DB::table('ledger_entries as le')
            ->join('transactions as t', 't.id', '=', 'le.transaction_id')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->where('le.user_id', $uid)->where('t.status', 'posted')->where('a.is_active', true)
            ->where('le.entry_date', '<=', $date)->sum('le.amount');

        return Money::add(Money::fromDatabase($opening), Money::fromDatabase($entries));
    }

    /** Active budgets that apply on the end date, compared with actual spending. */
    private function budgetEvaluations(string $to)
    {
        $date = Carbon::parse($to)->startOfDay();

        return Budget::query()->with('category:id,name')->where('is_active', true)->orderBy('scope')->orderBy('name')->get()
            ->map(function (Budget $budget) use ($date) {
                $result = $this->budgets->evaluate($budget, $date);

                return array_merge($result, [
                    'id' => $budget->id,
                    'name' => $budget->name,
                    'scope' => $budget->scope->value,
                    'period' => $budget->period,
                    'category' => $budget->category?->name,
                ]);
            })
            ->filter(fn ($row) => $row['applies'])
            ->values();
    }
}
