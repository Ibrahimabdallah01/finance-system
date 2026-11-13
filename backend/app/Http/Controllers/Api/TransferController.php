<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\StoreTransferRequest;
use App\Http\Requests\Transactions\UpdateTransferRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\LedgerService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Money moved between the owner's own accounts. The system decides whether it is a plain
 * transfer, an owner withdrawal (business -> personal) or an owner contribution.
 */
class TransferController extends Controller
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    public function store(StoreTransferRequest $request): JsonResponse
    {
        $data = $request->validated();

        $transaction = $this->withFieldNames(function () use ($data) {
            return $this->ledger->post(array_merge($data, [
                'type' => $this->ledger->transferTypeFor($data['from_account_id'], $data['to_account_id']),
                'account_id' => $data['from_account_id'],
            ]));
        });

        return $this->respond($transaction, $data['from_account_id'], 201);
    }

    public function update(UpdateTransferRequest $request, Transaction $transaction): JsonResponse
    {
        if (! $transaction->type->isPair()) {
            throw ValidationException::withMessages(['transaction' => ['Only transfers can be edited here.']]);
        }

        $changes = $request->validated();

        if (isset($changes['from_account_id'])) {
            $changes['account_id'] = $changes['from_account_id'];
        }

        $updated = $this->withFieldNames(fn () => $this->ledger->update($transaction, $changes));

        $source = $updated->entries->first(fn ($entry) => bccomp($entry->amount, '0', 2) < 0)?->account_id;

        return $this->respond($updated, $source, 200);
    }

    private function respond(Transaction $transaction, mixed $sourceAccountId, int $status): JsonResponse
    {
        return (new TransactionResource($transaction))
            ->additional(['meta' => ['warnings' => $this->ledger->negativeBalanceWarnings([$sourceAccountId])]])
            ->response()
            ->setStatusCode($status);
    }

    /** The service names the source account "account_id"; the transfer API calls it "from_account_id". */
    private function withFieldNames(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            $errors = [];

            foreach ($e->errors() as $field => $messages) {
                $errors[$field === 'account_id' ? 'from_account_id' : $field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }
}
