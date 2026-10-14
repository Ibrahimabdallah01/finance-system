<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\ListTransactionsRequest;
use App\Http\Requests\Transactions\VoidTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\LedgerService;

class TransactionController extends Controller
{
    public function index(ListTransactionsRequest $request)
    {
        $filters = $request->validated();

        $query = Transaction::query()
            ->with('entries.account:id,name,scope')
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['scope'] ?? null, fn ($q, $v) => $q->where('scope', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('transaction_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('transaction_date', '<=', $v))
            ->when($filters['account_id'] ?? null, fn ($q, $v) => $q->whereHas(
                'entries', fn ($entries) => $entries->where('account_id', $v)
            ))
            ->when($filters['search'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';

                $q->where(fn ($inner) => $inner
                    ->where('description', 'like', $like)
                    ->orWhere('recipient', 'like', $like)
                    ->orWhere('reference', 'like', $like));
            })
            ->orderByDesc('transaction_date')
            ->orderByDesc('id');

        return TransactionResource::collection($query->paginate($filters['per_page'] ?? 25));
    }

    public function show(Transaction $transaction): TransactionResource
    {
        return new TransactionResource($transaction->load('entries.account:id,name,scope'));
    }

    public function void(VoidTransactionRequest $request, Transaction $transaction, LedgerService $ledger): TransactionResource
    {
        return new TransactionResource($ledger->void($transaction, $request->validated('reason')));
    }
}
