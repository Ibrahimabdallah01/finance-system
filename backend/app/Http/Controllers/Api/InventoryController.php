<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ListUnitsRequest;
use App\Http\Requests\Inventory\PurchaseRequest;
use App\Http\Requests\Inventory\UnitUpdateRequest;
use App\Http\Requests\Inventory\WriteOffRequest;
use App\Http\Requests\Sales\ReportPeriodRequest;
use App\Http\Resources\InventoryUnitResource;
use App\Models\InventoryUnit;
use App\Services\AuditLogger;
use App\Services\InventoryReportService;
use App\Services\InventoryService;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Stock in. Creates an asset and the cash movement, never an expense. */
    public function purchase(PurchaseRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->inventory->purchase($data);

        return response()->json([
            'data' => [
                'total_cost' => $result['total_cost'],
                'quantity_added' => $result['quantity_added'],
                'transaction_id' => $result['transaction']?->id,
                'debt_id' => $result['debt']?->id,
                'units' => InventoryUnitResource::collection(collect($result['units']))->resolve($request),
            ],
            'meta' => [
                'warnings' => $this->ledger->negativeBalanceWarnings([$data['account_id'] ?? null]),
            ],
        ], 201);
    }

    public function writeOff(WriteOffRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->inventory->writeOff($request->validated())], 201);
    }

    public function summary(ReportPeriodRequest $request, InventoryReportService $report): JsonResponse
    {
        return response()->json(['data' => $report->summary($request->validated('from'), $request->validated('to'))]);
    }

    public function units(ListUnitsRequest $request)
    {
        $filters = $request->validated();

        $query = InventoryUnit::query()
            ->with(['product:id,name,brand,model', 'supplier:id,name', 'saleItems.sale:id,sale_number,sale_date,status'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($filters['search'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';

                $q->where(fn ($i) => $i->where('imei', 'like', $like)->orWhere('serial_number', 'like', $like)
                    ->orWhere('storage', 'like', $like)->orWhere('color', 'like', $like));
            })
            ->orderByDesc('id');

        return InventoryUnitResource::collection($query->paginate($filters['per_page'] ?? 25));
    }

    public function showUnit(InventoryUnit $unit): InventoryUnitResource
    {
        return new InventoryUnitResource($unit->load(['product:id,name,brand,model', 'supplier:id,name', 'saleItems.sale:id,sale_number,sale_date,status']));
    }

    public function updateUnit(UnitUpdateRequest $request, InventoryUnit $unit): InventoryUnitResource
    {
        $data = $request->validated();

        if (($data['imei'] ?? null) !== null || ($data['serial_number'] ?? null) !== null) {
            if ($unit->status->value !== 'in_stock') {
                throw ValidationException::withMessages(['imei' => ['The IMEI and serial number can only be changed while the phone is in stock.']]);
            }
        }

        $old = $unit->only(array_keys($data));
        $unit->update($data);
        $this->audit->record($unit, 'updated', $old, $unit->only(array_keys($data)));

        return new InventoryUnitResource($unit->load(['product:id,name,brand,model', 'supplier:id,name']));
    }
}
