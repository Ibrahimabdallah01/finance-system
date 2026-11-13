<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Budgets\BudgetDateRequest;
use App\Http\Requests\Budgets\StoreBudgetRequest;
use App\Http\Requests\Budgets\UpdateBudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use App\Services\AuditLogger;
use App\Services\BudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetService $service,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Every budget compared with actual spending in the period that contains ?date (default today). */
    public function index(BudgetDateRequest $request)
    {
        $date = $this->date($request);

        $budgets = Budget::query()->with('category:id,name')
            ->when($request->validated('scope'), fn ($q, $scope) => $q->where('scope', $scope))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('scope')->orderBy('period')->orderBy('name')
            ->get();

        foreach ($budgets as $budget) {
            $budget->setAttribute('performance', $this->service->evaluate($budget, $date));
        }

        return BudgetResource::collection($budgets);
    }

    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $budget = DB::transaction(function () use ($request) {
            $budget = Budget::create($request->validated());
            $this->audit->record($budget, 'created', null, $request->validated());

            return $budget;
        });

        return (new BudgetResource($this->present($budget->refresh(), Carbon::today())))->response()->setStatusCode(201);
    }

    public function show(BudgetDateRequest $request, Budget $budget): BudgetResource
    {
        return new BudgetResource($this->present($budget, $this->date($request)));
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): BudgetResource
    {
        $old = $budget->only(['name', 'amount', 'end_date', 'is_active', 'notes']);

        DB::transaction(function () use ($request, $budget, $old) {
            $budget->update($request->validated());
            $this->audit->record($budget, 'updated', $old, $budget->refresh()->only(array_keys($old)));
        });

        return new BudgetResource($this->present($budget->refresh(), Carbon::today()));
    }

    public function destroy(Budget $budget): Response
    {
        DB::transaction(function () use ($budget) {
            $this->audit->record($budget, 'deleted', $budget->only(['name', 'amount', 'period']), null);
            $budget->delete();
        });

        return response()->noContent();
    }

    /** Budget versus actual for the last N periods (oldest first), for charts. */
    public function history(BudgetDateRequest $request, Budget $budget): JsonResponse
    {
        return response()->json(['data' => [
            'budget' => (new BudgetResource($budget->load('category:id,name')))->resolve($request),
            'periods' => $this->service->history($budget, $this->date($request), (int) ($request->validated('periods') ?? 6)),
        ]]);
    }

    private function present(Budget $budget, Carbon $date): Budget
    {
        $budget->load('category:id,name');
        $budget->setAttribute('performance', $this->service->evaluate($budget, $date));

        return $budget;
    }

    private function date(BudgetDateRequest $request): Carbon
    {
        return Carbon::parse($request->validated('date') ?? now()->toDateString())->startOfDay();
    }
}
