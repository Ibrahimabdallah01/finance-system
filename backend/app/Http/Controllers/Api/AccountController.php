<?php

namespace App\Http\Controllers\Api;

use App\Enums\Scope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\LedgerStatementRequest;
use App\Http\Requests\Accounts\StoreAccountRequest;
use App\Http\Requests\Accounts\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request)
    {
        $scope = Scope::tryFrom((string) $request->query('scope'));

        $accounts = Account::query()->withBalance()
            ->when($scope, fn ($query) => $query->where('accounts.scope', $scope->value))
            ->when($request->has('active'), fn ($query) => $query->where('accounts.is_active', $request->boolean('active')))
            ->orderBy('accounts.scope')
            ->orderBy('accounts.name')
            ->get();

        // Business and personal money are always reported separately.
        $totals = [
            'business' => Money::sum($accounts->filter(fn ($a) => $a->scope === Scope::Business)->pluck('balance')),
            'personal' => Money::sum($accounts->filter(fn ($a) => $a->scope === Scope::Personal)->pluck('balance')),
            'all' => Money::sum($accounts->pluck('balance')),
        ];

        return AccountResource::collection($accounts)->additional(['meta' => ['totals' => $totals]]);
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['opening_balance'] = Money::fromInput($data['opening_balance'] ?? '0');

        $account = DB::transaction(function () use ($data) {
            $account = Account::create($data);
            $this->audit->record($account, 'created', null, $account->refresh()->auditSnapshot());

            return $account;
        });

        return (new AccountResource($this->withBalance($account)))->response()->setStatusCode(201);
    }

    public function show(Account $account): AccountResource
    {
        return new AccountResource($this->withBalance($account));
    }

    public function update(UpdateAccountRequest $request, Account $account): AccountResource
    {
        $data = $request->validated();

        if (isset($data['opening_balance_date'])) {
            $firstEntry = $account->ledgerEntries()->min('entry_date');

            if ($firstEntry !== null && $data['opening_balance_date'] > $firstEntry) {
                throw ValidationException::withMessages([
                    'opening_balance_date' => ["The opening date cannot be after the first transaction on this account ({$firstEntry})."],
                ]);
            }
        }

        if (isset($data['opening_balance'])) {
            $data['opening_balance'] = Money::fromInput($data['opening_balance']);
        }

        DB::transaction(function () use ($account, $data) {
            $old = $account->auditSnapshot();
            $account->update($data);
            $this->audit->record($account, 'updated', $old, $account->refresh()->auditSnapshot());
        });

        return new AccountResource($this->withBalance($account));
    }

    /** Only accounts that were never used can be deleted; otherwise deactivate them. */
    public function destroy(Account $account): Response
    {
        if ($account->ledgerEntries()->exists() || $account->reconciliations()->exists()) {
            throw ValidationException::withMessages([
                'account' => ['This account has transactions. Deactivate it instead of deleting it.'],
            ]);
        }

        DB::transaction(function () use ($account) {
            $this->audit->record($account, 'deleted', $account->auditSnapshot(), null);
            $account->delete();
        });

        return response()->noContent();
    }

    /** Account statement: posted entries with the running balance after each one, newest first. */
    public function ledger(LedgerStatementRequest $request, Account $account): JsonResponse
    {
        $filters = $request->validated();

        $running = DB::table('ledger_entries as le')
            ->join('transactions as t', 't.id', '=', 'le.transaction_id')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->where('le.account_id', $account->id)
            ->where('le.user_id', $request->user()->id)
            ->where('t.status', 'posted')
            ->selectRaw('le.id, le.transaction_id, le.entry_date, le.amount, a.opening_balance + SUM(le.amount) OVER (ORDER BY le.entry_date, le.id) AS balance_after');

        $statement = DB::query()->fromSub($running, 'x')
            ->join('transactions as tx', 'tx.id', '=', 'x.transaction_id')
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('x.entry_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('x.entry_date', '<=', $to))
            ->orderByDesc('x.entry_date')
            ->orderByDesc('x.id')
            ->select('x.id', 'x.transaction_id', 'x.entry_date', 'x.amount', 'x.balance_after',
                'tx.type', 'tx.description', 'tx.recipient', 'tx.reference');

        return response()->json($statement->paginate($filters['per_page'] ?? 50));
    }

    private function withBalance(Account $account): Account
    {
        return Account::query()->withBalance()->findOrFail($account->getKey());
    }
}
