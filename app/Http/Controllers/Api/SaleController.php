<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ListSalesRequest;
use App\Http\Requests\Sales\SaleCancelRequest;
use App\Http\Requests\Sales\SaleCostRequest;
use App\Http\Requests\Sales\SalePaymentRequest;
use App\Http\Requests\Sales\SaleReturnRequest;
use App\Http\Requests\Sales\SaleStoreRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Services\SalesService;
use Illuminate\Http\JsonResponse;

class SaleController extends Controller
{
    public function __construct(private readonly SalesService $sales)
    {
    }

    public function index(ListSalesRequest $request)
    {
        $filters = $request->validated();

        $query = Sale::query()
            ->with(SalesService::RELATIONS)
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('sale_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('sale_date', '<=', $v))
            ->when($filters['search'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';

                $q->where(fn ($i) => $i->where('sale_number', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)));
            })
            ->orderByDesc('sale_date')
            ->orderByDesc('id');

        return SaleResource::collection($query->paginate($filters['per_page'] ?? 25));
    }

    public function store(SaleStoreRequest $request): JsonResponse
    {
        return (new SaleResource($this->sales->create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(Sale $sale): SaleResource
    {
        return new SaleResource($this->fresh($sale));
    }

    public function cancel(SaleCancelRequest $request, Sale $sale): SaleResource
    {
        return new SaleResource($this->sales->cancel($sale, $request->validated('reason')));
    }

    public function payment(SalePaymentRequest $request, Sale $sale): JsonResponse
    {
        $this->sales->addPayment($sale, $request->validated());

        return (new SaleResource($this->fresh($sale)))->response()->setStatusCode(201);
    }

    public function refund(SalePaymentRequest $request, Sale $sale): JsonResponse
    {
        $this->sales->refund($sale, $request->validated());

        return (new SaleResource($this->fresh($sale)))->response()->setStatusCode(201);
    }

    public function saleReturn(SaleReturnRequest $request, Sale $sale): JsonResponse
    {
        $this->sales->recordReturn($sale, $request->validated());

        return (new SaleResource($this->fresh($sale)))->response()->setStatusCode(201);
    }

    public function cost(SaleCostRequest $request, Sale $sale): JsonResponse
    {
        $this->sales->recordCost($sale, $request->validated());

        return (new SaleResource($this->fresh($sale)))->response()->setStatusCode(201);
    }

    private function fresh(Sale $sale): Sale
    {
        return Sale::query()->with(SalesService::RELATIONS)->findOrFail($sale->getKey());
    }
}
