<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\SupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\InventoryUnit;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search'));

        return SupplierResource::collection(
            Supplier::query()
                ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
                ->orderBy('name')
                ->paginate(50)
        );
    }

    public function store(SupplierRequest $request)
    {
        return (new SupplierResource(Supplier::create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(Supplier $supplier): SupplierResource
    {
        return new SupplierResource($supplier);
    }

    public function update(SupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $supplier->update($request->validated());

        return new SupplierResource($supplier);
    }

    public function destroy(Supplier $supplier): Response
    {
        if (InventoryUnit::query()->where('supplier_id', $supplier->id)->exists()
            || DB::table('debts')->where('supplier_id', $supplier->id)->exists()) {
            throw ValidationException::withMessages(['supplier' => ['This supplier has stock or debts recorded and cannot be deleted.']]);
        }

        $supplier->delete();

        return response()->noContent();
    }
}
