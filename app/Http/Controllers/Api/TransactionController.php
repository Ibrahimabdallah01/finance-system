<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\ListTransactionsRequest;
use App\Http\Requests\Transactions\VoidTransactionRequest;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\TransactionResource;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Services\LedgerService;
use App\Support\Money;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * Filterable history. meta.summary.by_type totals POSTED rows per type for the same filters
     * (voided rows never count), so totals of different kinds are never mixed together.
     */
    public function index(ListTransactionsRequest $request)
    {
        $filters = $request->validated();

        $filtered = Transaction::query()
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['scope'] ?? null, fn ($q, $v) => $q->where('scope', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('transaction_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('transaction_date', '<=', $v))
            ->when($filters['account_id'] ?? null, fn ($q, $v) => $q->whereHas(
                'entries', fn ($entries) => $entries->where('account_id', $v)
            ))
            ->when($filters['category_id'] ?? null, function ($q, $id) {
                // A parent category includes its subcategories.
                $ids = TransactionCategory::query()
                    ->where(fn ($c) => $c->where('id', $id)->orWhere('parent_id', $id))
                    ->pluck('id');

                $q->whereIn('category_id', $ids);
            })
            ->when($filters['search'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';

                $q->where(fn ($inner) => $inner
                    ->where('description', 'like', $like)
                    ->orWhere('recipient', 'like', $like)
                    ->orWhere('reference', 'like', $like));
            });

        $page = (clone $filtered)
            ->with(['entries.account:id,name,scope', 'category:id,name,parent_id'])
            ->withCount('receipts')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        $byType = [];

        $rows = (clone $filtered)->where('status', 'posted')
            ->selectRaw('type, COUNT(*) as row_count, SUM(amount) as row_total')
            ->groupBy('type')
            ->get();

        foreach ($rows as $row) {
            $byType[$row->getRawOriginal('type')] = [
                'count' => (int) $row->row_count,
                'total' => Money::fromDatabase($row->row_total),
            ];
        }

        return TransactionResource::collection($page)
            ->additional(['meta' => ['summary' => ['by_type' => $byType]]]);
    }

    public function show(Transaction $transaction): TransactionResource
    {
        return new TransactionResource(
            $transaction->load(['entries.account:id,name,scope', 'category:id,name,parent_id'])->loadCount('receipts')
        );
    }

    public function void(VoidTransactionRequest $request, Transaction $transaction, LedgerService $ledger): TransactionResource
    {
        return new TransactionResource($ledger->void($transaction, $request->validated('reason')));
    }

    /** Change history of one transaction (created, updated, voided, receipts). */
    public function history(Request $request, Transaction $transaction)
    {
        return AuditLogResource::collection(
            AuditLog::query()
                ->where('user_id', $request->user()->id)
                ->where('auditable_type', $transaction->getMorphClass())
                ->where('auditable_id', $transaction->id)
                ->orderBy('id')
                ->get()
        );
    }
}
