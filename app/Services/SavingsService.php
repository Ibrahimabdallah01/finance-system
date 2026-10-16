<?php

namespace App\Services;

use App\Models\GoalContribution;
use App\Models\SavingsGoal;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Savings goals. Moving money into a savings account is a TRANSFER (never an expense).
 * Saved amount = sum of contributions (+ saved, - withdrawn) whose transfer is still posted.
 */
final class SavingsService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function figures(SavingsGoal $goal): array
    {
        $saved = Money::fromDatabase(
            DB::table('goal_contributions as c')
                ->leftJoin('transactions as t', 't.id', '=', 'c.transaction_id')
                ->where('c.savings_goal_id', $goal->id)
                ->where(fn ($q) => $q->whereNull('c.transaction_id')->orWhere('t.status', 'posted'))
                ->sum('c.amount')
        );

        $target = (string) $goal->target_amount;
        $remaining = Money::compare($saved, $target) >= 0 ? '0.00' : Money::sub($target, $saved);
        $monthsLeft = null;
        $requiredPerMonth = null;

        if ($goal->target_date !== null && Money::compare($remaining, '0') > 0) {
            $days = Carbon::today()->diffInDays($goal->target_date, false);

            if ($days > 0) {
                $monthsLeft = max(1, (int) ceil($days / 30));
                $requiredPerMonth = Money::div($remaining, $monthsLeft);
            }
        }

        return [
            'saved' => $saved,
            'target' => $target,
            'remaining' => $remaining,
            'percent_complete' => Money::div(Money::mul($saved, 100), $target),
            'is_complete' => Money::compare($saved, $target) >= 0,
            'months_left' => $monthsLeft,
            'required_per_month' => $requiredPerMonth,
            'target_date_passed' => $goal->target_date !== null && $goal->target_date->lt(Carbon::today()) && Money::compare($remaining, '0') > 0,
        ];
    }

    /**
     * Adds money to a goal. With from_account_id the money really moves (a transfer into the goal's
     * savings account); without it the amount is only earmarked from money already in that account.
     */
    public function contribute(SavingsGoal $goal, array $data): GoalContribution
    {
        $this->requireUser();

        return DB::transaction(function () use ($goal, $data) {
            $goal = $this->lockedActive($goal);
            $amount = Money::fromInput($data['amount']);
            $date = InventoryService::date($data['contribution_date'] ?? null, 'contribution_date');
            $transaction = null;

            if (! empty($data['from_account_id'])) {
                if ($goal->account_id === null) {
                    throw self::invalid('from_account_id', 'Choose the savings account for this goal before moving money into it.');
                }

                $transaction = $this->ledger->post([
                    'type' => $this->ledger->transferTypeFor($data['from_account_id'], $goal->account_id),
                    'account_id' => $data['from_account_id'],
                    'to_account_id' => $goal->account_id,
                    'amount' => $amount,
                    'transaction_date' => $date->toDateString(),
                    'description' => 'Savings: '.$goal->name,
                    'notes' => $data['note'] ?? null,
                ]);
            }

            $contribution = $this->record($goal, $amount, $date, $transaction?->id, $data['note'] ?? null);
            $this->syncStatus($goal);
            $this->audit->record($goal, 'contribution_added', null, ['amount' => $amount, 'transaction_id' => $transaction?->id]);

            return $contribution;
        });
    }

    public function withdraw(SavingsGoal $goal, array $data): GoalContribution
    {
        $this->requireUser();

        return DB::transaction(function () use ($goal, $data) {
            $goal = $this->lockedActive($goal);
            $amount = Money::fromInput($data['amount']);
            $date = InventoryService::date($data['contribution_date'] ?? null, 'contribution_date');
            $saved = $this->figures($goal)['saved'];

            if (Money::compare($amount, $saved) > 0) {
                throw self::invalid('amount', 'You cannot withdraw more than the saved amount ('.Money::format($saved).').');
            }

            $transaction = null;

            if (! empty($data['to_account_id'])) {
                if ($goal->account_id === null) {
                    throw self::invalid('to_account_id', 'This goal has no savings account to take the money from.');
                }

                $transaction = $this->ledger->post([
                    'type' => $this->ledger->transferTypeFor($goal->account_id, $data['to_account_id']),
                    'account_id' => $goal->account_id,
                    'to_account_id' => $data['to_account_id'],
                    'amount' => $amount,
                    'transaction_date' => $date->toDateString(),
                    'description' => 'Withdrawn from savings: '.$goal->name,
                    'notes' => $data['note'] ?? null,
                ]);
            }

            $contribution = $this->record($goal, Money::sub('0', $amount), $date, $transaction?->id, $data['note'] ?? null);
            $this->syncStatus($goal);
            $this->audit->record($goal, 'withdrawal', null, ['amount' => $amount, 'transaction_id' => $transaction?->id]);

            return $contribution;
        });
    }

    private function record(SavingsGoal $goal, string $signedAmount, Carbon $date, ?int $transactionId, ?string $note): GoalContribution
    {
        $contribution = new GoalContribution([
            'amount' => $signedAmount,
            'contribution_date' => $date->toDateString(),
            'note' => $note,
        ]);
        $contribution->forceFill(['savings_goal_id' => $goal->id, 'transaction_id' => $transactionId])->save();

        return $contribution;
    }

    /** A goal becomes achieved when the target is reached and active again if money is taken out. */
    private function syncStatus(SavingsGoal $goal): void
    {
        $complete = $this->figures($goal)['is_complete'];

        if ($complete && $goal->status === 'active') {
            $goal->forceFill(['status' => 'achieved'])->save();
        } elseif (! $complete && $goal->status === 'achieved') {
            $goal->forceFill(['status' => 'active'])->save();
        }
    }

    private function lockedActive(SavingsGoal $goal): SavingsGoal
    {
        $locked = SavingsGoal::query()->lockForUpdate()->findOrFail($goal->getKey());

        if ($locked->status === 'cancelled') {
            throw self::invalid('goal', 'This goal is cancelled.');
        }

        return $locked;
    }

    private function requireUser(): void
    {
        if (Auth::id() === null) {
            throw new LogicException('SavingsService must run for a logged-in user.');
        }
    }

    private static function invalid(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => [$message]]);
    }
}
