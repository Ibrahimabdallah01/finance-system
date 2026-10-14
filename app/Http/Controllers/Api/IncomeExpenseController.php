<?php

namespace App\Http\Controllers\Api;

use App\Enums\Scope;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\StoreIncomeExpenseRequest;
use App\Http\Requests\Transactions\UpdateIncomeExpenseRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Personal income, personal expenses, business expenses (and non-sales business income).
 * Business sales revenue is NOT recorded here: it comes from sales in Phase 6.
 * Buying stock is NOT an expense: it is an inventory purchase (Phase 6).
 */
class IncomeExpenseController extends Controller
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    public function storeIncome(StoreIncomeExpenseRequest $request): JsonResponse
    {
        return $this->store($request, TransactionType::Income);
    }

    public function storeExpense(StoreIncomeExpenseRequest $request): JsonResponse
    {
        return $this->store($request, TransactionType::Expense);
    }

    public function updateIncome(UpdateIncomeExpenseRequest $request, Transaction $transaction): JsonResponse
    {
        return $this->update($request, $transaction, TransactionType::Income);
    }

    public function updateExpense(UpdateIncomeExpenseRequest $request, Transaction $transaction): JsonResponse
    {
        return $this->update($request, $transaction, TransactionType::Expense);
    }

    private function store(StoreIncomeExpenseRequest $request, TransactionType $type): JsonResponse
    {
        $data = $request->validated();

        $this->assertRecipient($type, Account::query()->find($data['account_id'])?->scope, $data['recipient'] ?? null);

        $transaction = $this->ledger->post(array_merge($data, ['type' => $type]));

        return $this->respond($transaction, $type, $data['account_id'], 201);
    }

    private function update(UpdateIncomeExpenseRequest $request, Transaction $transaction, TransactionType $type): JsonResponse
    {
        if ($transaction->type !== $type) {
            throw ValidationException::withMessages([
                'transaction' => ['This record is not '.($type === TransactionType::Income ? 'an income' : 'an expense').' record.'],
            ]);
        }

        $data = $request->validated();

        $scope = isset($data['account_id'])
            ? Account::query()->find($data['account_id'])?->scope
            : $transaction->scope;
        $recipient = array_key_exists('recipient', $data) ? $data['recipient'] : $transaction->recipient;

        $this->assertRecipient($type, $scope, $recipient);

        $updated = $this->ledger->update($transaction, $data);
        $accountId = $updated->entries->first()?->account_id;

        return $this->respond($updated, $type, $accountId, 200);
    }

    private function respond(Transaction $transaction, TransactionType $type, mixed $accountId, int $status): JsonResponse
    {
        $warnings = $type === TransactionType::Expense
            ? $this->ledger->negativeBalanceWarnings([$accountId])
            : [];

        return (new TransactionResource($transaction->load('category:id,name,parent_id')))
            ->additional(['meta' => ['warnings' => $warnings]])
            ->response()
            ->setStatusCode($status);
    }

    /** Business expenses must say who was paid. */
    private function assertRecipient(TransactionType $type, ?Scope $scope, ?string $recipient): void
    {
        if ($type === TransactionType::Expense && $scope === Scope::Business && trim((string) $recipient) === '') {
            throw ValidationException::withMessages(['recipient' => ['A recipient is required for business expenses.']]);
        }
    }
}
