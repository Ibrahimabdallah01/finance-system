<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * "Money owed to me" and "money I owe": open debts plus what customers still owe on sales.
 * Customer sale balances are read from the sales, never copied into debts, so nothing is counted twice.
 */
final class DebtSummaryService
{
    /** @return list<array{0: Debt, 1: array<string, mixed>}> open debts with their figures */
    private function openDebts(): array
    {
        return Debt::query()->with(DebtService::RELATIONS)->orderBy('id')->get()
            ->map(fn (Debt $debt) => [$debt, DebtCalculator::figures($debt)])
            ->filter(fn ($pair) => $pair[1]['status'] === 'open')
            ->values()
            ->all();
    }

    /** @return list<array{0: Sale, 1: array<string, string>}> completed sales with money still owed */
    private function unpaidSales(): array
    {
        return Sale::query()->where('status', 'completed')->with(['customer', 'items', 'returns', 'transactions'])->orderBy('id')->get()
            ->map(fn (Sale $sale) => [$sale, SaleCalculator::figures($sale)])
            ->filter(fn ($pair) => Money::compare($pair[1]['outstanding_balance'], '0') > 0)
            ->values()
            ->all();
    }

    public function summary(int $days): array
    {
        $today = Carbon::today();
        $horizon = $today->copy()->addDays($days);
        $debts = $this->openDebts();
        $sales = $this->unpaidSales();

        $total = fn (array $pairs, ?string $direction = null, ?string $source = null) => Money::sum(
            collect($pairs)
                ->filter(fn ($p) => ($direction === null || $p[0]->direction === $direction)
                    && ($source === null || $p[0]->source === $source))
                ->map(fn ($p) => $p[1]['outstanding_balance'])
        );

        $owedDebts = $total($debts, 'receivable');
        $owedSales = Money::sum(collect($sales)->map(fn ($p) => $p[1]['outstanding_balance']));
        $iOwe = $total($debts, 'payable');

        $overdue = ['receivable' => ['count' => 0, 'amount' => '0.00'], 'payable' => ['count' => 0, 'amount' => '0.00']];
        $upcoming = [];

        foreach ($debts as [$debt, $figures]) {
            if ($figures['overdue']) {
                $overdue[$debt->direction]['count']++;
                $overdue[$debt->direction]['amount'] = Money::add($overdue[$debt->direction]['amount'], $figures['outstanding_balance']);
            }

            if ($figures['schedule'] !== []) {
                foreach ($figures['schedule'] as $row) {
                    if ($row['status'] !== 'paid' && Carbon::parse($row['due_date'])->lte($horizon)) {
                        $upcoming[] = $this->item('debt_installment', $debt->direction, $debt->party_name, $debt->id, $row['due_date'], $row['remaining'], $row['status'] === 'overdue');
                    }
                }
            } elseif ($debt->due_date !== null && $debt->due_date->lte($horizon)) {
                $upcoming[] = $this->item('debt', $debt->direction, $debt->party_name, $debt->id, $debt->due_date->toDateString(), $figures['outstanding_balance'], $figures['overdue']);
            }
        }

        foreach ($sales as [$sale, $figures]) {
            if ($sale->due_date === null) {
                continue;
            }

            $late = $sale->due_date->lt($today);

            if ($late) {
                $overdue['receivable']['count']++;
                $overdue['receivable']['amount'] = Money::add($overdue['receivable']['amount'], $figures['outstanding_balance']);
            }

            if ($sale->due_date->lte($horizon)) {
                $upcoming[] = $this->item('sale', 'receivable', $sale->customer?->name ?? 'Customer', $sale->id, $sale->due_date->toDateString(), $figures['outstanding_balance'], $late, $sale->sale_number);
            }
        }

        usort($upcoming, fn ($a, $b) => [$a['due_date'], $a['kind']] <=> [$b['due_date'], $b['kind']]);

        return [
            'owed_to_me' => [
                'debts' => $owedDebts,
                'customer_sales' => $owedSales,
                'total' => Money::add($owedDebts, $owedSales),
            ],
            'i_owe' => [
                'total' => $iOwe,
                'loans' => $total($debts, 'payable', 'loan'),
                'supplier_credit' => $total($debts, 'payable', 'supplier_credit'),
                'other' => $total($debts, 'payable', 'other'),
            ],
            'overdue' => $overdue,
            'upcoming' => $upcoming,
            'horizon_days' => $days,
        ];
    }

    /** Who owes me money: customers (from sales) and people I lent to or who owe me. */
    public function receivables(): array
    {
        $today = Carbon::today();
        $byCustomer = [];

        foreach ($this->unpaidSales() as [$sale, $figures]) {
            $key = $sale->customer_id ?? 0;
            $byCustomer[$key]['customer'] ??= $sale->customer ? [
                'id' => $sale->customer->id, 'name' => $sale->customer->name, 'phone' => $sale->customer->phone,
            ] : null;
            $byCustomer[$key]['outstanding_total'] = Money::add($byCustomer[$key]['outstanding_total'] ?? '0.00', $figures['outstanding_balance']);
            $byCustomer[$key]['sales'][] = [
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'sale_date' => $sale->sale_date->toDateString(),
                'due_date' => $sale->due_date?->toDateString(),
                'outstanding_balance' => $figures['outstanding_balance'],
                'overdue' => $sale->due_date !== null && $sale->due_date->lt($today),
            ];
        }

        $debts = collect($this->openDebts())
            ->filter(fn ($p) => $p[0]->direction === 'receivable')
            ->map(fn ($p) => [
                'id' => $p[0]->id,
                'party_name' => $p[0]->party_name,
                'source' => $p[0]->source,
                'due_date' => $p[0]->due_date?->toDateString(),
                'outstanding_balance' => $p[1]['outstanding_balance'],
                'overdue' => $p[1]['overdue'],
            ])->values()->all();

        $customers = array_values($byCustomer);
        $customerTotal = Money::sum(collect($customers)->pluck('outstanding_total'));
        $debtTotal = Money::sum(collect($debts)->pluck('outstanding_balance'));

        return [
            'customers' => $customers,
            'debts' => $debts,
            'totals' => [
                'customers' => $customerTotal,
                'debts' => $debtTotal,
                'all' => Money::add($customerTotal, $debtTotal),
            ],
        ];
    }

    private function item(string $kind, string $direction, string $party, int $id, string $dueDate, string $amount, bool $overdue, ?string $reference = null): array
    {
        return [
            'kind' => $kind,
            'direction' => $direction,
            'party' => $party,
            'id' => $id,
            'reference' => $reference,
            'due_date' => $dueDate,
            'amount' => $amount,
            'overdue' => $overdue,
        ];
    }
}
