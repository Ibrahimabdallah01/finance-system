<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Debts\DebtRepaymentRequest;
use App\Http\Requests\Debts\DebtScheduleRequest;
use App\Http\Requests\Debts\DebtStoreRequest;
use App\Http\Requests\Debts\DebtWriteOffRequest;
use App\Http\Requests\Debts\ListDebtsRequest;
use App\Http\Resources\DebtResource;
use App\Models\Debt;
use App\Services\DebtCalculator;
use App\Services\DebtService;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;

class DebtController extends Controller
{
    public function __construct(
        private readonly DebtService $debts,
        private readonly LedgerService $ledger,
    ) {
    }

    /** Debts of every kind. The status filter uses the derived status (open, settled, written_off, cancelled). */
    public function index(ListDebtsRequest $request)
    {
        $filters = $request->validated();

        $debts = Debt::query()->with(DebtService::RELATIONS)
            ->when($filters['direction'] ?? null, fn ($q, $v) => $q->where('direction', $v))
            ->when($filters['scope'] ?? null, fn ($q, $v) => $q->where('scope', $v))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('party_name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->orderByDesc('issued_on')->orderByDesc('id')
            ->get();

        if (! empty($filters['status'])) {
            $debts = $debts->filter(fn (Debt $debt) => DebtCalculator::figures($debt)['status'] === $filters['status'])->values();
        }

        return DebtResource::collection($debts);
    }

    public function store(DebtStoreRequest $request): JsonResponse
    {
        return (new DebtResource($this->debts->create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(Debt $debt): DebtResource
    {
        return new DebtResource($this->fresh($debt));
    }

    public function repay(DebtRepaymentRequest $request, Debt $debt): JsonResponse
    {
        $data = $request->validated();
        $this->debts->repay($debt, $data);

        return (new DebtResource($this->fresh($debt)))
            ->additional(['meta' => ['warnings' => $this->ledger->negativeBalanceWarnings([$data['account_id']])]])
            ->response()
            ->setStatusCode(201);
    }

    public function writeOff(DebtWriteOffRequest $request, Debt $debt): DebtResource
    {
        return new DebtResource($this->debts->writeOff($debt, $request->validated('reason')));
    }

    public function schedule(DebtScheduleRequest $request, Debt $debt): DebtResource
    {
        return new DebtResource($this->debts->setSchedule($debt, $request->validated('installments')));
    }

    private function fresh(Debt $debt): Debt
    {
        return Debt::query()->with(DebtService::RELATIONS)->findOrFail($debt->getKey());
    }
}
