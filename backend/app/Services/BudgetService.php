<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Budget performance. Actual spending counts only POSTED `expense` transactions of the budget's
 * scope, so transfers, owner withdrawals, stock purchases, loan principal and savings are never counted.
 */
final class BudgetService
{
    public const WARNING_PERCENT = '80';

    public const OVER_PERCENT = '100';

    /** @return array{0: Carbon, 1: Carbon} first and last day of the period that contains $date */
    public static function bounds(string $period, Carbon $date): array
    {
        return match ($period) {
            'daily' => [$date->copy()->startOfDay(), $date->copy()->endOfDay()],
            'weekly' => [$date->copy()->startOfWeek(Carbon::MONDAY), $date->copy()->endOfWeek(Carbon::SUNDAY)],
            'monthly' => [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()],
            default => [$date->copy()->startOfYear(), $date->copy()->endOfYear()],
        };
    }

    /** Budget versus actual for the period that contains $date. */
    public function evaluate(Budget $budget, Carbon $date): array
    {
        [$start, $end] = self::bounds($budget->period, $date);
        $spent = $this->spent($budget, $start->toDateString(), $end->toDateString());
        $percent = Money::div(Money::mul($spent, 100), $budget->amount);

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'budget' => (string) $budget->amount,
            'spent' => $spent,
            'remaining' => Money::sub($budget->amount, $spent), // negative = overspent
            'percent_used' => $percent,
            'status' => match (true) {
                Money::compare($percent, self::OVER_PERCENT) >= 0 => 'over',
                Money::compare($percent, self::WARNING_PERCENT) >= 0 => 'warning',
                default => 'ok',
            },
            'applies' => $this->applies($budget, $date),
        ];
    }

    /** The last $periods periods ending with the one that contains $date, oldest first. */
    public function history(Budget $budget, Carbon $date, int $periods): array
    {
        $rows = [];

        for ($i = 0; $i < $periods; $i++) {
            $day = match ($budget->period) {
                'daily' => $date->copy()->subDays($i),
                'weekly' => $date->copy()->subWeeks($i),
                'monthly' => $date->copy()->subMonthsNoOverflow($i),
                default => $date->copy()->subYears($i),
            };

            $rows[] = $this->evaluate($budget, $day);
        }

        return array_reverse($rows);
    }

    /** Messages for budgets that a just-recorded expense has pushed to 80 % or more. */
    public function warningsFor(Transaction $transaction): array
    {
        if ($transaction->type !== TransactionType::Expense || $transaction->status !== 'posted') {
            return [];
        }

        $date = $transaction->transaction_date;
        $categoryIds = array_values(array_filter([$transaction->category_id, $transaction->category?->parent_id]));

        $budgets = Budget::query()
            ->where('is_active', true)
            ->where('scope', $transaction->scope->value)
            ->whereDate('start_date', '<=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $date->toDateString()))
            ->where(fn ($q) => $q->whereNull('category_id')
                ->when($categoryIds !== [], fn ($inner) => $inner->orWhereIn('category_id', $categoryIds)))
            ->get();

        $messages = [];

        foreach ($budgets as $budget) {
            $result = $this->evaluate($budget, $date);

            if ($result['status'] !== 'ok') {
                $messages[] = sprintf(
                    'Budget "%s" is %s%% used (%s of %s)%s.',
                    $budget->name,
                    $result['percent_used'],
                    Money::format($result['spent']),
                    Money::format($result['budget']),
                    $result['status'] === 'over' ? ' - over budget' : ''
                );
            }
        }

        return $messages;
    }

    private function spent(Budget $budget, string $from, string $to): string
    {
        $query = DB::table('transactions')
            ->where('user_id', Auth::id())
            ->where('status', 'posted')
            ->where('type', 'expense')
            ->where('scope', $budget->scope->value)
            ->whereBetween('transaction_date', [$from, $to]);

        if ($budget->category_id !== null) {
            // A parent category includes its subcategories.
            $ids = TransactionCategory::query()
                ->where(fn ($c) => $c->where('id', $budget->category_id)->orWhere('parent_id', $budget->category_id))
                ->pluck('id');

            $query->whereIn('category_id', $ids);
        }

        return Money::fromDatabase($query->sum('amount'));
    }

    private function applies(Budget $budget, Carbon $date): bool
    {
        return $budget->is_active
            && $date->gte($budget->start_date)
            && ($budget->end_date === null || $date->lte($budget->end_date));
    }
}
