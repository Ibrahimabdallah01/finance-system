<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\GoalMoneyRequest;
use App\Http\Requests\Savings\SavingsGoalRequest;
use App\Http\Resources\SavingsGoalResource;
use App\Models\Account;
use App\Models\SavingsGoal;
use App\Services\AuditLogger;
use App\Services\LedgerService;
use App\Services\SavingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SavingsGoalController extends Controller
{
    public function __construct(
        private readonly SavingsService $savings,
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index()
    {
        $goals = SavingsGoal::query()->with('account:id,name,scope')->orderBy('status')->orderBy('target_date')->orderBy('name')->get();

        foreach ($goals as $goal) {
            $goal->setAttribute('figures', $this->savings->figures($goal));
        }

        return SavingsGoalResource::collection($goals);
    }

    public function store(SavingsGoalRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->assertAccount($data['account_id'] ?? null);

        $goal = DB::transaction(function () use ($data) {
            $goal = SavingsGoal::create(array_merge($data, ['status' => 'active']));
            $this->audit->record($goal, 'created', null, $data);

            return $goal;
        });

        return (new SavingsGoalResource($this->present($goal->refresh())))->response()->setStatusCode(201);
    }

    public function show(SavingsGoal $goal): SavingsGoalResource
    {
        return new SavingsGoalResource($this->present($goal));
    }

    public function update(SavingsGoalRequest $request, SavingsGoal $goal): SavingsGoalResource
    {
        $data = $request->validated();

        if (array_key_exists('account_id', $data)) {
            $this->assertAccount($data['account_id']);

            if ((int) $data['account_id'] !== (int) $goal->account_id && $goal->contributions()->exists()) {
                throw ValidationException::withMessages([
                    'account_id' => ['The savings account cannot change once money has been saved. Withdraw the savings first.'],
                ]);
            }
        }

        DB::transaction(function () use ($goal, $data) {
            $old = $goal->only(array_keys($data));
            $goal->update($data);
            $this->audit->record($goal, 'updated', $old, $goal->refresh()->only(array_keys($data)));
        });

        return new SavingsGoalResource($this->present($goal->refresh()));
    }

    public function destroy(SavingsGoal $goal): Response
    {
        if ($goal->contributions()->exists()) {
            throw ValidationException::withMessages(['goal' => ['This goal has savings recorded. Cancel it instead of deleting it.']]);
        }

        DB::transaction(function () use ($goal) {
            $this->audit->record($goal, 'deleted', $goal->only(['name', 'target_amount']), null);
            $goal->delete();
        });

        return response()->noContent();
    }

    public function contribute(GoalMoneyRequest $request, SavingsGoal $goal): JsonResponse
    {
        $data = $request->validated();
        $this->savings->contribute($goal, $data);

        return $this->respond($goal, $data['from_account_id'] ?? null, 201);
    }

    public function withdraw(GoalMoneyRequest $request, SavingsGoal $goal): JsonResponse
    {
        $data = $request->validated();
        $this->savings->withdraw($goal, $data);

        return $this->respond($goal, $goal->account_id, 201);
    }

    private function respond(SavingsGoal $goal, mixed $sourceAccountId, int $status): JsonResponse
    {
        return (new SavingsGoalResource($this->present($goal->refresh())))
            ->additional(['meta' => ['warnings' => $this->ledger->negativeBalanceWarnings([$sourceAccountId])]])
            ->response()
            ->setStatusCode($status);
    }

    private function present(SavingsGoal $goal): SavingsGoal
    {
        $goal->load(['account:id,name,scope', 'contributions.transaction:id,status']);
        $goal->setAttribute('figures', $this->savings->figures($goal));

        return $goal;
    }

    private function assertAccount(mixed $accountId): void
    {
        if ($accountId !== null && ! Account::query()->whereKey($accountId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['account_id' => ['Account not found or inactive.']]);
        }
    }
}
