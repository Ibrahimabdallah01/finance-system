<?php

namespace Tests\Concerns;

use App\Enums\Scope;
use App\Enums\Tracking;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\TransactionCategory;
use App\Models\User;

/** A ready-made phone shop: business cash account, supplier, customer, three products, a delivery category. */
trait SetsUpShop
{
    use CreatesFinanceData;

    protected User $user;

    protected Account $shop;       // business cash, 1,000,000

    protected Account $wallet;     // personal

    protected Supplier $supplier;

    protected Customer $customer;

    protected Product $phoneProduct;   // serialized

    protected Product $accessory;      // counted, reorder level 5

    protected Product $directProduct;  // sold directly

    protected TransactionCategory $delivery;

    protected function setUpShop(): void
    {
        $this->user = $this->makeUser();
        $this->actingAs($this->user);

        $this->shop = $this->makeAccount($this->user, ['name' => 'Shop cash', 'opening_balance' => '1000000.00']);
        $this->wallet = $this->makeAccount($this->user, ['name' => 'Wallet', 'scope' => Scope::Personal]);
        $this->supplier = $this->makeSupplier($this->user, 'Dubai Traders');
        $this->customer = $this->makeCustomer($this->user, 'Asha');

        $this->phoneProduct = $this->makeProduct($this->user, ['name' => 'iPhone 13']);
        $this->accessory = $this->makeProduct($this->user, [
            'name' => 'USB-C charger', 'product_type' => 'accessory', 'tracking' => Tracking::Quantity, 'reorder_level' => 5,
        ]);
        $this->directProduct = $this->makeProduct($this->user, [
            'name' => 'Screen protector (bought on demand)', 'product_type' => 'accessory', 'tracking' => Tracking::Direct,
        ]);

        $this->delivery = $this->makeCategory($this->user, ['name' => 'Delivery', 'scope' => Scope::Business]);
    }

    protected function makeSupplier(User $user, string $name): Supplier
    {
        $supplier = new Supplier(['name' => $name]);
        $supplier->user_id = $user->id;
        $supplier->save();

        return $supplier;
    }

    protected function makeCustomer(User $user, string $name): Customer
    {
        $customer = new Customer(['name' => $name, 'phone' => '0712000000']);
        $customer->user_id = $user->id;
        $customer->save();

        return $customer;
    }

    protected function makeProduct(User $user, array $attributes = []): Product
    {
        $product = new Product(array_merge([
            'product_type' => 'phone',
            'tracking' => Tracking::Serialized,
            'name' => 'Product',
            'is_active' => true,
        ], $attributes));
        $product->user_id = $user->id;
        $product->save();

        return $product->refresh();
    }

    protected function imei(int $n): string
    {
        return sprintf('35693803564%04d', $n);
    }

    protected function balanceOf(Account $account): string
    {
        return $this->getJson("/api/accounts/{$account->id}")->json('data.balance');
    }

    /** Buys one phone (price + extra) from the shop cash account and returns its unit id. */
    protected function stockPhone(int $n = 1, string $price = '600000', string $extra = '5000', ?Product $product = null): int
    {
        return $this->postJson('/api/inventory/purchases', [
            'product_id' => ($product ?? $this->phoneProduct)->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => $this->daysAgo(10),
            'account_id' => $this->shop->id,
            'units' => [[
                'imei' => $this->imei($n), 'storage' => '128GB', 'color' => 'Black',
                'item_condition' => 'used', 'purchase_price' => $price, 'additional_costs' => $extra,
            ]],
        ])->assertCreated()->json('data.units.0.id');
    }

    protected function stockAccessories(int $quantity, string $unitCost, string $extra = '0'): void
    {
        $this->postJson('/api/inventory/purchases', [
            'product_id' => $this->accessory->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => $this->daysAgo(10),
            'account_id' => $this->shop->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'additional_costs' => $extra,
        ])->assertCreated();
    }

    protected function pay(string $amount): array
    {
        return ['account_id' => $this->shop->id, 'amount' => $amount, 'payment_method' => 'cash'];
    }

    protected function sellPhone(int $unitId, string $price = '750000', array $payments = [], array $extra = [])
    {
        return $this->postJson('/api/sales', array_merge([
            'customer_id' => $this->customer->id,
            'sale_date' => $this->daysAgo(5),
            'items' => [[
                'product_id' => $this->phoneProduct->id, 'inventory_unit_id' => $unitId,
                'quantity' => 1, 'unit_price' => $price,
            ]],
            'payments' => $payments,
        ], $extra));
    }

    protected function profitLoss(): array
    {
        return $this->getJson('/api/reports/profit-loss?from='.$this->daysAgo(30).'&to='.now()->toDateString())
            ->assertOk()->json('data');
    }
}
