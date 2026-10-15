<?php

namespace App\Services;

use App\Enums\CategoryKind;
use App\Enums\Scope;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\InventoryMovement;
use App\Models\InventoryUnit;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The only place that writes transactions and ledger entries.
 * Every call runs in one DB transaction, validates the accounting rules, and is audited.
 * It must run for a logged-in user: the owner scope on Account is what stops one user
 * from posting to another user's account.
 */
final class LedgerService
{
    private const PASSTHROUGH = [
        'category_id', 'sale_id', 'debt_id', 'payment_method',
        'description', 'recipient', 'reference', 'notes',
    ];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * @param array{
     *   type: TransactionType|string, transaction_date: string, amount: string|int|float,
     *   account_id: int, to_account_id?: int, direction?: string,
     *   category_id?: ?int, sale_id?: ?int, debt_id?: ?int, payment_method?: ?string,
     *   description?: ?string, recipient?: ?string, reference?: ?string, notes?: ?string
     * } $input
     */
    public function post(array $input): Transaction
    {
        $this->requireUser();

        return DB::transaction(function () use ($input) {
            $plan = $this->plan($input);

            $tx = Transaction::create(array_merge($plan['attributes'], [
                'scope' => $plan['scope'],
                'type' => $plan['type'],
                'status' => 'posted',
                'transaction_date' => $plan['date']->toDateString(),
                'amount' => $plan['amount'],
            ]));

            $this->writeEntries($tx, $plan);
            $this->audit->record($tx, 'created', null, $this->snapshot($tx));

            return $tx->load('entries.account');
        });
    }

    /** Edits a posted transaction. The type never changes; ledger entries are rebuilt. */
    public function update(Transaction $transaction, array $changes): Transaction
    {
        $this->requireUser();

        return DB::transaction(function () use ($transaction, $changes) {
            $tx = Transaction::query()->lockForUpdate()->findOrFail($transaction->getKey());

            if ($tx->isVoided()) {
                throw self::invalid('transaction', 'A voided transaction cannot be edited.');
            }

            $tx->load('entries');
            $old = $this->snapshot($tx);

            $plan = $this->plan(array_merge($this->currentInput($tx), $changes, ['type' => $tx->type]), $tx);

            $tx->entries()->delete();
            $tx->forceFill(array_merge($plan['attributes'], [
                'scope' => $plan['scope'],
                'transaction_date' => $plan['date']->toDateString(),
                'amount' => $plan['amount'],
            ]))->save();

            $this->writeEntries($tx, $plan);
            $tx->unsetRelation('entries')->load('entries');
            $this->audit->record($tx, 'updated', $old, $this->snapshot($tx));

            return $tx->load('entries.account');
        });
    }

    /** Voiding keeps the record and its history but removes it from every balance and report. */
    public function void(Transaction $transaction, string $reason): Transaction
    {
        $this->requireUser();

        return DB::transaction(function () use ($transaction, $reason) {
            $tx = Transaction::query()->lockForUpdate()->findOrFail($transaction->getKey());

            if ($tx->isVoided()) {
                throw self::invalid('transaction', 'This transaction is already voided.');
            }

            if ($tx->type === TransactionType::InventoryPurchase
                && ($tx->sale_id !== null
                    || InventoryUnit::query()->where('purchase_transaction_id', $tx->id)->exists()
                    || InventoryMovement::query()->where('transaction_id', $tx->id)->exists())) {
                throw self::invalid('transaction', 'This payment is tied to stock or a sale. Return the stock, write it off, or cancel the sale instead of voiding the payment.');
            }

            $old = $this->snapshot($tx);

            $tx->forceFill([
                'status' => 'voided',
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            $this->audit->record($tx, 'voided', $old, $this->snapshot($tx->refresh()));

            return $tx->load('entries.account');
        });
    }

    /** Decides whether a move between two accounts is a transfer, owner withdrawal or contribution. */
    public function transferTypeFor(int|string $fromId, int|string $toId): TransactionType
    {
        $this->requireUser();

        $accounts = Account::query()->whereIn('id', [$fromId, $toId])->get()->keyBy('id');
        $from = $accounts->get((int) $fromId) ?? throw self::invalid('account_id', 'Source account not found.');
        $to = $accounts->get((int) $toId) ?? throw self::invalid('to_account_id', 'Destination account not found.');

        return match (true) {
            $from->scope === $to->scope => TransactionType::Transfer,
            $from->scope === Scope::Business => TransactionType::OwnerWithdrawal,
            default => TransactionType::OwnerContribution,
        };
    }

    /** @return list<string> Human-readable warnings for accounts whose balance is below zero. */
    public function negativeBalanceWarnings(iterable $accountIds): array
    {
        $ids = collect($accountIds)->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return Account::query()->withBalance()->whereIn('id', $ids)->get()
            ->filter(fn (Account $account) => Money::compare($account->balance, '0') < 0)
            ->map(fn (Account $account) => sprintf(
                'Account "%s" balance is now negative (%s). Check for a missing deposit or a wrong amount.',
                $account->name,
                Money::format($account->balance)
            ))
            ->values()
            ->all();
    }

    /**
     * Validates everything and works out the ledger legs. Writes nothing.
     *
     * @return array{type: TransactionType, scope: Scope, amount: string, date: Carbon, legs: list<array{0: Account, 1: string}>, attributes: array}
     */
    private function plan(array $input, ?Transaction $existing = null): array
    {
        $type = $input['type'] instanceof TransactionType
            ? $input['type']
            : TransactionType::from((string) $input['type']);

        $amount = Money::fromInput($input['amount']);

        if (Money::compare($amount, '0') <= 0) {
            throw self::invalid('amount', 'The amount must be greater than zero.');
        }

        $date = Carbon::parse((string) $input['transaction_date'])->startOfDay();

        if ($date->gt(Carbon::today())) {
            throw self::invalid('transaction_date', 'The transaction date cannot be in the future.');
        }

        $ids = array_values(array_filter([$input['account_id'] ?? null, $input['to_account_id'] ?? null]));
        $accounts = Account::query()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

        /** @var Account $source */
        $source = $accounts->get((int) ($input['account_id'] ?? 0))
            ?? throw self::invalid('account_id', 'Account not found.');

        $this->assertUsable($source, $date, 'account_id');

        if ($type->isPair()) {
            /** @var Account $destination */
            $destination = $accounts->get((int) ($input['to_account_id'] ?? 0))
                ?? throw self::invalid('to_account_id', 'Destination account not found.');

            $this->assertUsable($destination, $date, 'to_account_id');

            if ($destination->id === $source->id) {
                throw self::invalid('to_account_id', 'Choose two different accounts.');
            }

            $scope = $this->assertPairScopes($type, $source, $destination);

            $legs = [[$source, Money::sub('0', $amount)], [$destination, $amount]];
        } else {
            if ($type->requiresBusinessAccount() && $source->scope !== Scope::Business) {
                throw self::invalid('account_id', 'This kind of transaction must use a business account.');
            }

            $sign = $type->sign();

            if ($sign === 0) {
                $direction = $input['direction'] ?? null;

                if (! in_array($direction, ['in', 'out'], true)) {
                    throw self::invalid('direction', 'Direction must be "in" or "out".');
                }

                $sign = $direction === 'in' ? 1 : -1;
            }

            $scope = $source->scope;
            $legs = [[$source, $sign > 0 ? $amount : Money::sub('0', $amount)]];
        }

        $categoryId = $input['category_id'] ?? null;

        if ($type === TransactionType::Income || $type === TransactionType::Expense) {
            if ($categoryId !== null) {
                $this->assertCategory($categoryId, $type, $scope, $existing?->category_id);
            }
        } elseif ($categoryId !== null) {
            throw self::invalid('category_id', 'Categories apply only to income and expenses.');
        }

        return [
            'type' => $type,
            'scope' => $scope,
            'amount' => $amount,
            'date' => $date,
            'legs' => $legs,
            'attributes' => array_intersect_key($input, array_flip(self::PASSTHROUGH)),
        ];
    }

    /** An already-used category may be deactivated later; editing such a record must still work. */
    private function assertCategory(int|string $categoryId, TransactionType $type, Scope $scope, int|string|null $currentCategoryId = null): void
    {
        $category = TransactionCategory::query()->find($categoryId)
            ?? throw self::invalid('category_id', 'Category not found.');

        if (! $category->is_active && (int) $category->id !== (int) $currentCategoryId) {
            throw self::invalid('category_id', 'This category is inactive.');
        }

        $expectedKind = $type === TransactionType::Income ? CategoryKind::Income : CategoryKind::Expense;

        if ($category->kind !== $expectedKind) {
            throw self::invalid('category_id', sprintf('Choose an %s category.', $expectedKind->value));
        }

        if ($category->scope !== $scope) {
            throw self::invalid('category_id', sprintf(
                'This is a %s category but the account is a %s account.',
                $category->scope->value,
                $scope->value
            ));
        }
    }

    private function assertUsable(Account $account, Carbon $date, string $field): void
    {
        if (! $account->is_active) {
            throw self::invalid($field, sprintf('Account "%s" is inactive.', $account->name));
        }

        if ($date->lt($account->opening_balance_date)) {
            throw self::invalid('transaction_date', sprintf(
                'The date is before the opening balance date (%s) of account "%s".',
                $account->opening_balance_date->toDateString(),
                $account->name
            ));
        }
    }

    private function assertPairScopes(TransactionType $type, Account $source, Account $destination): Scope
    {
        switch ($type) {
            case TransactionType::Transfer:
                if ($source->scope !== $destination->scope) {
                    throw self::invalid('to_account_id', 'A transfer must stay within business accounts or within personal accounts. Use an owner withdrawal or contribution to move money between them.');
                }

                return $source->scope;

            case TransactionType::OwnerWithdrawal:
                if ($source->scope !== Scope::Business || $destination->scope !== Scope::Personal) {
                    throw self::invalid('to_account_id', 'An owner withdrawal moves money from a business account to a personal account.');
                }

                return Scope::Business;

            default: // OwnerContribution
                if ($source->scope !== Scope::Personal || $destination->scope !== Scope::Business) {
                    throw self::invalid('to_account_id', 'An owner contribution moves money from a personal account to a business account.');
                }

                return Scope::Personal;
        }
    }

    private function writeEntries(Transaction $tx, array $plan): void
    {
        foreach ($plan['legs'] as [$account, $signedAmount]) {
            $entry = new LedgerEntry([
                'account_id' => $account->id,
                'amount' => $signedAmount,
                'entry_date' => $plan['date']->toDateString(),
            ]);
            $entry->transaction_id = $tx->id;
            $entry->user_id = $tx->user_id;
            $entry->save();
        }
    }

    /** Rebuilds the input array of an existing transaction so an edit can change only some fields. */
    private function currentInput(Transaction $tx): array
    {
        $input = [
            'amount' => $tx->amount,
            'transaction_date' => $tx->transaction_date->toDateString(),
        ];

        foreach (self::PASSTHROUGH as $key) {
            $input[$key] = $tx->{$key};
        }

        if ($tx->type->isPair()) {
            $input['account_id'] = $tx->entries->first(fn ($e) => Money::compare($e->amount, '0') < 0)?->account_id;
            $input['to_account_id'] = $tx->entries->first(fn ($e) => Money::compare($e->amount, '0') > 0)?->account_id;
        } else {
            $entry = $tx->entries->first();
            $input['account_id'] = $entry?->account_id;
            $input['direction'] = $entry && Money::compare($entry->amount, '0') > 0 ? 'in' : 'out';
        }

        return $input;
    }

    private function snapshot(Transaction $tx): array
    {
        $tx->loadMissing('entries');

        return [
            'type' => $tx->type->value,
            'scope' => $tx->scope->value,
            'status' => $tx->status,
            'transaction_date' => $tx->transaction_date->toDateString(),
            'amount' => $tx->amount,
            'description' => $tx->description,
            'recipient' => $tx->recipient,
            'reference' => $tx->reference,
            'legs' => $tx->entries
                ->map(fn (LedgerEntry $e) => ['account_id' => $e->account_id, 'amount' => $e->amount])
                ->values()
                ->all(),
        ];
    }

    private function requireUser(): void
    {
        if (Auth::id() === null) {
            throw new LogicException('LedgerService must run for a logged-in user.');
        }
    }

    private static function invalid(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => [$message]]);
    }
}
