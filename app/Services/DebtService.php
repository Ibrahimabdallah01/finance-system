<?php

namespace App\Services;

use App\Enums\Scope;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Loans and other debts.
 *  - Money you borrow is a liability, not income. Money you lend is a receivable, not an expense.
 *  - Repaying principal reduces the liability; it is never an expense.
 *  - Interest is tracked separately: an expense when you pay it, income when you receive it.
 */
final class DebtService
{
    public const RELATIONS = ['supplier', 'customer', 'transactions', 'installments'];

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Loans move cash now (one transaction). Other debts are only recorded. */
    public function create(array $data): Debt
    {
        $this->requireUser();

        return DB::transaction(function () use ($data) {
            $date = InventoryService::date($data['issued_on'] ?? null, 'issued_on');
            $amount = Money::fromInput($data['original_amount']);
            $scope = Scope::from($data['scope']);
            $payable = $data['direction'] === 'payable';
            $accountId = $data['account_id'] ?? null;

            if (! empty($data['supplier_id']) && ! Supplier::query()->whereKey($data['supplier_id'])->exists()) {
                throw self::invalid('supplier_id', 'Supplier not found.');
            }

            if (! empty($data['customer_id']) && ! Customer::query()->whereKey($data['customer_id'])->exists()) {
                throw self::invalid('customer_id', 'Customer not found.');
            }

            if ($data['source'] === 'loan') {
                if (empty($accountId)) {
                    throw self::invalid('account_id', 'Choose the account the loan money moved through.');
                }

                $this->assertAccountScope($accountId, $scope);
            } elseif (! empty($accountId)) {
                throw self::invalid('account_id', 'Only loans move cash. Leave the account empty for this kind of debt.');
            }

            $debt = Debt::create([
                'scope' => $scope->value,
                'direction' => $data['direction'],
                'source' => $data['source'],
                'party_name' => $data['party_name'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'original_amount' => $amount,
                'issued_on' => $date->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'status' => 'open',
                'notes' => $data['notes'] ?? null,
            ]);

            if ($data['source'] === 'loan') {
                $this->ledger->post([
                    'type' => $payable ? TransactionType::LoanReceived : TransactionType::LoanGiven,
                    'account_id' => $accountId,
                    'amount' => $amount,
                    'transaction_date' => $date->toDateString(),
                    'debt_id' => $debt->id,
                    'payment_method' => $data['payment_method'] ?? null,
                    'reference' => $data['reference'] ?? null,
                    'description' => ($payable ? 'Loan received from ' : 'Loan given to ').$data['party_name'],
                    'recipient' => $data['party_name'],
                ]);
            }

            $this->audit->record($debt, 'created', null, [
                'direction' => $data['direction'], 'source' => $data['source'], 'amount' => $amount,
            ]);

            return $debt->load(self::RELATIONS);
        });
    }

    /**
     * Records a repayment: the principal always, plus optional interest as its own transaction.
     *
     * @return array{principal: Transaction, interest: ?Transaction}
     */
    public function repay(Debt $debt, array $data): array
    {
        $this->requireUser();

        return DB::transaction(function () use ($debt, $data) {
            $debt = Debt::query()->lockForUpdate()->findOrFail($debt->getKey())->load(self::RELATIONS);
            $figures = DebtCalculator::figures($debt);

            if ($figures['status'] !== 'open') {
                throw self::invalid('debt', 'This debt is '.str_replace('_', ' ', $figures['status']).'.');
            }

            $amount = Money::fromInput($data['amount']);

            if (Money::compare($amount, $figures['outstanding_balance']) > 0) {
                throw self::invalid('amount', 'The repayment is more than the outstanding balance ('.Money::format($figures['outstanding_balance']).').');
            }

            $date = InventoryService::date($data['transaction_date'] ?? null, 'transaction_date');

            if ($date->lt($debt->issued_on)) {
                throw self::invalid('transaction_date', 'The date cannot be before the debt was issued ('.$debt->issued_on->toDateString().').');
            }

            $this->assertAccountScope($data['account_id'] ?? 0, Scope::from($debt->scope));

            $payable = $debt->direction === 'payable';
            $common = [
                'account_id' => $data['account_id'],
                'transaction_date' => $date->toDateString(),
                'debt_id' => $debt->id,
                'payment_method' => $data['payment_method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'recipient' => $debt->party_name,
            ];

            $principal = $this->ledger->post(array_merge($common, [
                'type' => $payable ? TransactionType::DebtRepaymentOut : TransactionType::DebtRepaymentIn,
                'amount' => $amount,
                'description' => ($payable ? 'Repayment to ' : 'Repayment from ').$debt->party_name,
                'notes' => $data['notes'] ?? null,
            ]));

            $interest = null;
            $interestAmount = Money::fromInput($data['interest_amount'] ?? '0');

            if (Money::compare($interestAmount, '0') > 0) {
                if (empty($data['interest_category_id'])) {
                    throw self::invalid('interest_category_id', 'Choose a category for the interest.');
                }

                $interest = $this->ledger->post(array_merge($common, [
                    'type' => $payable ? TransactionType::Expense : TransactionType::Income,
                    'amount' => $interestAmount,
                    'category_id' => $data['interest_category_id'],
                    'description' => ($payable ? 'Interest paid to ' : 'Interest received from ').$debt->party_name,
                ]));
            }

            $this->audit->record($debt, 'repaid', null, [
                'principal' => $amount, 'interest' => $interestAmount, 'transaction_id' => $principal->id,
            ]);

            return ['principal' => $principal, 'interest' => $interest];
        });
    }

    /** Forgives or gives up on a debt. It leaves the totals; no ledger entry is made. */
    public function writeOff(Debt $debt, string $reason): Debt
    {
        $this->requireUser();

        return DB::transaction(function () use ($debt, $reason) {
            $debt = Debt::query()->lockForUpdate()->findOrFail($debt->getKey())->load(self::RELATIONS);
            $status = DebtCalculator::figures($debt)['status'];

            if ($status !== 'open') {
                throw self::invalid('debt', 'Only an open debt can be written off (this one is '.str_replace('_', ' ', $status).').');
            }

            $debt->forceFill([
                'status' => 'written_off',
                'notes' => trim(($debt->notes ? $debt->notes."\n" : '').'Written off: '.$reason),
            ])->save();

            $this->audit->record($debt, 'written_off', null, ['reason' => $reason]);

            return $debt->load(self::RELATIONS);
        });
    }

    /** Replaces the planned repayment schedule. */
    public function setSchedule(Debt $debt, array $installments): Debt
    {
        $this->requireUser();

        return DB::transaction(function () use ($debt, $installments) {
            $debt = Debt::query()->lockForUpdate()->findOrFail($debt->getKey());
            $total = '0.00';

            foreach (array_values($installments) as $i => $row) {
                $amount = Money::fromInput($row['amount_due']);

                if (Money::compare($amount, '0') <= 0) {
                    throw self::invalid("installments.{$i}.amount_due", 'Each installment must be greater than zero.');
                }

                if ($debt->issued_on->gt($row['due_date'])) {
                    throw self::invalid("installments.{$i}.due_date", 'An installment cannot be due before the debt was issued.');
                }

                $total = Money::add($total, $amount);
            }

            if (Money::compare($total, $debt->original_amount) > 0) {
                throw self::invalid('installments', 'The installments add up to more than the debt ('.Money::format($debt->original_amount).').');
            }

            $debt->installments()->delete();

            foreach (array_values($installments) as $row) {
                $installment = new DebtInstallment([
                    'due_date' => $row['due_date'],
                    'amount_due' => Money::fromInput($row['amount_due']),
                    'note' => $row['note'] ?? null,
                ]);
                $installment->debt_id = $debt->id;
                $installment->save();
            }

            $this->audit->record($debt, 'schedule_set', null, ['installments' => count($installments), 'total' => $total]);

            return $debt->load(self::RELATIONS);
        });
    }

    private function assertAccountScope(mixed $accountId, Scope $scope): void
    {
        $account = Account::query()->find($accountId)
            ?? throw self::invalid('account_id', 'Account not found.');

        if ($account->scope !== $scope) {
            throw self::invalid('account_id', 'The account must be a '.$scope->value.' account to match this debt.');
        }
    }

    private function requireUser(): void
    {
        if (Auth::id() === null) {
            throw new LogicException('DebtService must run for a logged-in user.');
        }
    }

    private static function invalid(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => [$message]]);
    }
}
