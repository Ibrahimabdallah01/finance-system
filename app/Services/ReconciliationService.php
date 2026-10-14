<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountReconciliation;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReconciliationService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Compares the recorded balance (as of $date) with the money actually counted.
     * Optionally posts an 'adjustment' transaction so the books match the count.
     */
    public function reconcile(
        Account $account,
        string|int|float $actualBalance,
        string $date,
        bool $postAdjustment,
        ?string $notes = null,
    ): AccountReconciliation {
        return DB::transaction(function () use ($account, $actualBalance, $date, $postAdjustment, $notes) {
            $account = Account::query()->lockForUpdate()->findOrFail($account->getKey());
            $actual = Money::fromInput($actualBalance);
            $day = Carbon::parse($date)->startOfDay();

            if ($day->gt(Carbon::today())) {
                throw ValidationException::withMessages(['reconciled_on' => ['The date cannot be in the future.']]);
            }

            if ($day->lt($account->opening_balance_date)) {
                throw ValidationException::withMessages(['reconciled_on' => ['The date is before the account opening balance date.']]);
            }

            $recorded = Account::query()->withBalance($day->toDateString())
                ->findOrFail($account->getKey())->balance;

            $difference = Money::sub($actual, $recorded);
            $adjustmentId = null;

            if (Money::compare($difference, '0') !== 0 && $postAdjustment) {
                $adjustment = $this->ledger->post([
                    'type' => TransactionType::Adjustment,
                    'account_id' => $account->id,
                    'amount' => ltrim($difference, '-'),
                    'direction' => Money::compare($difference, '0') > 0 ? 'in' : 'out',
                    'transaction_date' => $day->toDateString(),
                    'description' => 'Reconciliation adjustment',
                    'notes' => $notes,
                ]);

                $adjustmentId = $adjustment->id;
            }

            $reconciliation = AccountReconciliation::create([
                'account_id' => $account->id,
                'reconciled_on' => $day->toDateString(),
                'recorded_balance' => $recorded,
                'actual_balance' => $actual,
                'difference' => $difference,
                'adjustment_transaction_id' => $adjustmentId,
                'notes' => $notes,
            ]);

            $this->audit->record($reconciliation, 'created', null, [
                'account_id' => $account->id,
                'reconciled_on' => $day->toDateString(),
                'recorded_balance' => $recorded,
                'actual_balance' => $actual,
                'difference' => $difference,
                'adjustment_transaction_id' => $adjustmentId,
            ]);

            return $reconciliation->load('adjustment');
        });
    }
}
