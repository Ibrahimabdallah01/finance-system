<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Debt;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Debt figures, all derived from records:
 *   outstanding = original amount - principal repayments (posted)
 *   status      = written_off | cancelled (its loan transaction was voided) | settled | open
 * Interest is tracked separately: an expense when you pay it, income when you receive it.
 */
final class DebtCalculator
{
    private const ORIGIN = [TransactionType::LoanReceived, TransactionType::LoanGiven];

    /** @return array<string, mixed> */
    public static function figures(Debt $debt): array
    {
        $debt->loadMissing(['transactions', 'installments']);

        $posted = $debt->transactions->filter(fn ($t) => $t->status === 'posted');
        $sum = fn (array $types) => Money::sum(
            $posted->filter(fn ($t) => in_array($t->type, $types, true))->pluck('amount')
        );

        $repaid = $sum([TransactionType::DebtRepaymentIn, TransactionType::DebtRepaymentOut]);
        $interest = $sum([TransactionType::Expense, TransactionType::Income]);
        $difference = Money::sub($debt->original_amount, $repaid);
        $outstanding = Money::compare($difference, '0') > 0 ? $difference : '0.00';

        $origins = $debt->transactions->filter(fn ($t) => in_array($t->type, self::ORIGIN, true));
        $cancelled = $origins->isNotEmpty() && $origins->every(fn ($t) => $t->status === 'voided');

        $status = match (true) {
            $debt->status === 'written_off' => 'written_off',
            $cancelled => 'cancelled',
            Money::compare($outstanding, '0') === 0 => 'settled',
            default => 'open',
        };

        $today = Carbon::today();
        $overdue = $status === 'open' && $debt->due_date !== null && $debt->due_date->lt($today);

        return [
            'status' => $status,
            'original_amount' => (string) $debt->original_amount,
            'repaid' => $repaid,
            'outstanding_balance' => $outstanding,
            'interest_total' => $interest,
            'overdue' => $overdue,
            'days_overdue' => $overdue ? (int) $debt->due_date->diffInDays($today) : 0,
            'origin_transaction_id' => $origins->first()?->id,
            'schedule' => self::schedule($debt, $repaid, $today),
        ];
    }

    /** Repayments are applied to the installments in due-date order. */
    private static function schedule(Debt $debt, string $repaid, Carbon $today): array
    {
        $left = $repaid;
        $rows = [];

        foreach ($debt->installments as $installment) {
            $due = (string) $installment->amount_due;
            $allocated = Money::compare($left, $due) >= 0 ? $due : $left;
            $left = Money::sub($left, $allocated);
            $remaining = Money::sub($due, $allocated);

            $rows[] = [
                'id' => $installment->id,
                'due_date' => $installment->due_date->toDateString(),
                'amount_due' => $due,
                'paid' => $allocated,
                'remaining' => $remaining,
                'note' => $installment->note,
                'status' => match (true) {
                    Money::compare($remaining, '0') === 0 => 'paid',
                    $installment->due_date->lt($today) => 'overdue',
                    Money::compare($allocated, '0') > 0 => 'partial',
                    default => 'upcoming',
                },
            ];
        }

        return $rows;
    }
}
