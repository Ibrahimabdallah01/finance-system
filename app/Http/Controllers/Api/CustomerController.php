<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\SaleCalculator;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search'));
        $like = '%'.addcslashes($search, '%_\\').'%';

        return CustomerResource::collection(
            Customer::query()
                ->when($search !== '', fn ($q) => $q->where(fn ($i) => $i->where('name', 'like', $like)->orWhere('phone', 'like', $like)))
                ->orderBy('name')
                ->paginate(50)
        );
    }

    public function store(CustomerRequest $request)
    {
        return (new CustomerResource(Customer::create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(Customer $customer): CustomerResource
    {
        $sales = $customer->sales()->where('status', 'completed')->with(['items', 'returns', 'transactions'])->get();

        $customer->setAttribute('outstanding_balance', Money::sum(
            $sales->map(fn ($sale) => SaleCalculator::figures($sale)['outstanding_balance'])
        ));

        return new CustomerResource($customer);
    }

    public function update(CustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer->update($request->validated());

        return new CustomerResource($customer);
    }

    public function destroy(Customer $customer): Response
    {
        if ($customer->sales()->exists() || DB::table('debts')->where('customer_id', $customer->id)->exists()) {
            throw ValidationException::withMessages(['customer' => ['This customer has sales or debts recorded and cannot be deleted.']]);
        }

        $customer->delete();

        return response()->noContent();
    }
}
