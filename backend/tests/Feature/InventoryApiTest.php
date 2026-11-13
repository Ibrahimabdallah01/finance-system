<?php

namespace Tests\Feature;

use App\Models\Debt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpShop;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    use RefreshDatabase, SetsUpShop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
    }

    public function test_a_product_can_be_created_and_its_tracking_cannot_change(): void
    {
        $id = $this->postJson('/api/products', [
            'product_type' => 'phone', 'tracking' => 'serialized', 'name' => 'Samsung A54', 'sku' => 'SAM-A54',
        ])->assertCreated()->assertJsonPath('data.tracking', 'serialized')->assertJsonPath('data.stock.quantity', 0)->json('data.id');

        $this->putJson("/api/products/{$id}", ['tracking' => 'quantity', 'name' => 'Samsung Galaxy A54'])
            ->assertOk()
            ->assertJsonPath('data.tracking', 'serialized')
            ->assertJsonPath('data.name', 'Samsung Galaxy A54');

        $this->postJson('/api/products', ['product_type' => 'phone', 'tracking' => 'serialized', 'name' => 'Dup', 'sku' => 'SAM-A54'])
            ->assertStatus(422)->assertJsonValidationErrors('sku');
    }

    public function test_buying_a_phone_moves_cash_and_creates_stock_but_no_expense(): void
    {
        $response = $this->postJson('/api/inventory/purchases', [
            'product_id' => $this->phoneProduct->id, 'supplier_id' => $this->supplier->id,
            'purchase_date' => $this->daysAgo(10), 'account_id' => $this->shop->id,
            'units' => [[
                'imei' => $this->imei(1), 'storage' => '128GB', 'color' => 'Blue',
                'purchase_price' => '600000', 'additional_costs' => '5000',
            ]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.total_cost', '605000.00')
            ->assertJsonPath('data.quantity_added', 1)
            ->assertJsonPath('data.units.0.cost', '605000.00')
            ->assertJsonPath('data.units.0.status', 'in_stock');

        $this->assertSame('395000.00', $this->balanceOf($this->shop));

        $this->getJson('/api/transactions?type=expense')->assertJsonPath('meta.summary.by_type', []);
        $this->getJson('/api/transactions?type=inventory_purchase')
            ->assertJsonPath('meta.summary.by_type.inventory_purchase.total', '605000.00');

        $pnl = $this->profitLoss();
        $this->assertSame('0.00', $pnl['operating_expenses']);
        $this->assertSame('0.00', $pnl['net_profit']);
        $this->assertSame('605000.00', $pnl['cash_view']['inventory_purchases_paid']);

        $this->getJson('/api/inventory/summary')
            ->assertJsonPath('data.totals.stock_value_at_cost', '605000.00')
            ->assertJsonPath('data.totals.phones_in_stock', 1);

        $this->assertNotNull($response->json('data.transaction_id'));
    }

    public function test_the_same_imei_cannot_be_added_twice_and_bad_imeis_are_rejected(): void
    {
        $this->stockPhone(1);

        $this->postJson('/api/inventory/purchases', [
            'product_id' => $this->phoneProduct->id, 'purchase_date' => $this->daysAgo(1), 'account_id' => $this->shop->id,
            'units' => [['imei' => $this->imei(1), 'purchase_price' => '100000']],
        ])->assertStatus(422)->assertJsonValidationErrors('units.0.imei');

        $this->postJson('/api/inventory/purchases', [
            'product_id' => $this->phoneProduct->id, 'purchase_date' => $this->daysAgo(1), 'account_id' => $this->shop->id,
            'units' => [
                ['imei' => $this->imei(2), 'purchase_price' => '100000'],
                ['imei' => $this->imei(2), 'purchase_price' => '100000'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('units.1.imei');

        $this->postJson('/api/inventory/purchases', [
            'product_id' => $this->phoneProduct->id, 'purchase_date' => $this->daysAgo(1), 'account_id' => $this->shop->id,
            'units' => [['imei' => '12345', 'purchase_price' => '100000']],
        ])->assertStatus(422)->assertJsonValidationErrors('units.0.imei');

        $this->assertSame('395000.00', $this->balanceOf($this->shop));
    }

    public function test_stock_must_be_paid_from_a_business_account(): void
    {
        $this->postJson('/api/inventory/purchases', [
            'product_id' => $this->phoneProduct->id, 'purchase_date' => $this->daysAgo(1), 'account_id' => $this->wallet->id,
            'units' => [['imei' => $this->imei(5), 'purchase_price' => '100000']],
        ])->assertStatus(422)->assertJsonValidationErrors('account_id');

        $this->postJson('/api/inventory/purchases', [
            'product_id' => $this->directProduct->id, 'purchase_date' => $this->daysAgo(1), 'account_id' => $this->shop->id,
            'quantity' => 2, 'unit_cost' => '1000',
        ])->assertStatus(422)->assertJsonValidationErrors('product_id');
    }

    public function test_a_credit_purchase_creates_a_debt_and_only_additional_costs_are_paid_now(): void
    {
        $this->postJson('/api/inventory/purchases', [
            'product_id' => $this->phoneProduct->id, 'supplier_id' => $this->supplier->id,
            'purchase_date' => $this->daysAgo(10), 'account_id' => $this->shop->id, 'on_credit' => true,
            'due_date' => now()->addDays(30)->toDateString(),
            'units' => [['imei' => $this->imei(7), 'purchase_price' => '600000', 'additional_costs' => '5000']],
        ])->assertCreated()->assertJsonPath('data.total_cost', '605000.00');

        $this->assertSame('995000.00', $this->balanceOf($this->shop));
        $this->assertDatabaseHas('debts', [
            'supplier_id' => $this->supplier->id, 'direction' => 'payable', 'source' => 'supplier_credit',
            'scope' => 'business', 'original_amount' => '600000.00', 'status' => 'open',
        ]);
        $this->assertSame(1, Debt::query()->count());

        // Without a supplier there is nobody to owe.
        $this->postJson('/api/inventory/purchases', [
            'product_id' => $this->phoneProduct->id, 'purchase_date' => $this->daysAgo(1), 'on_credit' => true,
            'units' => [['imei' => $this->imei(8), 'purchase_price' => '100000']],
        ])->assertStatus(422)->assertJsonValidationErrors('supplier_id');
    }

    public function test_a_credit_purchase_with_no_extra_costs_needs_no_account(): void
    {
        $this->postJson('/api/inventory/purchases', [
            'product_id' => $this->phoneProduct->id, 'supplier_id' => $this->supplier->id,
            'purchase_date' => $this->daysAgo(2), 'on_credit' => true,
            'units' => [['imei' => $this->imei(9), 'purchase_price' => '300000']],
        ])->assertCreated()->assertJsonPath('data.transaction_id', null);

        $this->assertSame('1000000.00', $this->balanceOf($this->shop));
    }

    public function test_opening_stock_records_stock_without_any_cash_movement(): void
    {
        $this->postJson('/api/inventory/purchases', [
            'product_id' => $this->phoneProduct->id, 'purchase_date' => $this->daysAgo(20), 'opening_stock' => true,
            'units' => [['imei' => $this->imei(10), 'purchase_price' => '600000', 'additional_costs' => '5000']],
        ])->assertCreated()->assertJsonPath('data.transaction_id', null)->assertJsonPath('data.units.0.acquisition_source', 'opening_stock');

        $this->assertSame('1000000.00', $this->balanceOf($this->shop));
        $this->getJson('/api/inventory/summary')->assertJsonPath('data.totals.stock_value_at_cost', '605000.00');
    }

    public function test_counted_items_use_a_weighted_average_cost(): void
    {
        $this->stockAccessories(10, '1000');
        $this->stockAccessories(10, '2000');

        $this->getJson("/api/products/{$this->accessory->id}")
            ->assertJsonPath('data.average_cost', '1500.00')
            ->assertJsonPath('data.stock.quantity', 20)
            ->assertJsonPath('data.stock.value_at_cost', '30000.00');

        $this->assertSame('970000.00', $this->balanceOf($this->shop));
    }

    public function test_low_stock_items_are_flagged(): void
    {
        $this->stockAccessories(3, '1000');

        $this->getJson('/api/inventory/summary')
            ->assertJsonCount(1, 'data.low_stock')
            ->assertJsonPath('data.low_stock.0.name', 'USB-C charger');

        $this->getJson("/api/products/{$this->accessory->id}")->assertJsonPath('data.stock.low_stock', true);
    }

    public function test_a_written_off_phone_leaves_stock_and_reduces_profit(): void
    {
        $keep = $this->stockPhone(1);
        $lose = $this->stockPhone(2);

        $this->postJson('/api/inventory/write-offs', [
            'inventory_unit_id' => $lose, 'write_off_date' => $this->daysAgo(1), 'reason' => 'Dropped and broken',
        ])->assertCreated()->assertJsonPath('data.cost', '605000.00');

        $this->getJson("/api/inventory/units/{$lose}")->assertJsonPath('data.status', 'written_off');
        $this->getJson('/api/inventory/summary')->assertJsonPath('data.totals.stock_value_at_cost', '605000.00');

        $pnl = $this->profitLoss();
        $this->assertSame('605000.00', $pnl['inventory_write_offs']);
        $this->assertSame('-605000.00', $pnl['net_profit']);

        // A phone already written off cannot be written off again, and a sold one cannot either.
        $this->postJson('/api/inventory/write-offs', [
            'inventory_unit_id' => $lose, 'write_off_date' => $this->daysAgo(1), 'reason' => 'Again',
        ])->assertStatus(422);

        $this->sellPhone($keep, '750000', [$this->pay('750000')])->assertCreated();
        $this->postJson('/api/inventory/write-offs', [
            'inventory_unit_id' => $keep, 'write_off_date' => $this->daysAgo(1), 'reason' => 'Sold already',
        ])->assertStatus(422);
    }

    public function test_counted_items_can_be_written_off_by_quantity(): void
    {
        $this->stockAccessories(10, '1000');

        $this->postJson('/api/inventory/write-offs', [
            'product_id' => $this->accessory->id, 'quantity' => 2, 'write_off_date' => $this->daysAgo(1), 'reason' => 'Water damage',
        ])->assertCreated()->assertJsonPath('data.cost', '2000.00');

        $this->getJson("/api/products/{$this->accessory->id}")->assertJsonPath('data.stock.quantity', 8);
        $this->assertSame('2000.00', $this->profitLoss()['inventory_write_offs']);

        $this->postJson('/api/inventory/write-offs', [
            'product_id' => $this->accessory->id, 'quantity' => 99, 'write_off_date' => $this->daysAgo(1), 'reason' => 'Too many',
        ])->assertStatus(422)->assertJsonValidationErrors('quantity');
    }

    public function test_a_stock_purchase_payment_cannot_be_voided_while_stock_is_attached(): void
    {
        $transactionId = $this->postJson('/api/inventory/purchases', [
            'product_id' => $this->phoneProduct->id, 'purchase_date' => $this->daysAgo(3), 'account_id' => $this->shop->id,
            'units' => [['imei' => $this->imei(11), 'purchase_price' => '100000']],
        ])->assertCreated()->json('data.transaction_id');

        $this->postJson("/api/transactions/{$transactionId}/void", ['reason' => 'Mistake'])
            ->assertStatus(422)->assertJsonValidationErrors('transaction');

        $this->assertSame('900000.00', $this->balanceOf($this->shop));
    }

    public function test_units_can_be_searched_and_their_details_edited_while_in_stock(): void
    {
        $unit = $this->stockPhone(3);
        $this->stockPhone(4);

        $this->getJson('/api/inventory/units?search='.substr($this->imei(3), -4))->assertJsonCount(1, 'data');
        $this->getJson('/api/inventory/units?status=in_stock')->assertJsonCount(2, 'data');

        $this->putJson("/api/inventory/units/{$unit}", ['color' => 'Midnight', 'imei' => $this->imei(33)])
            ->assertOk()->assertJsonPath('data.color', 'Midnight')->assertJsonPath('data.imei', $this->imei(33));

        $this->putJson("/api/inventory/units/{$unit}", ['imei' => $this->imei(4)])
            ->assertStatus(422)->assertJsonValidationErrors('imei');

        $this->sellPhone($unit, '750000', [$this->pay('750000')])->assertCreated();

        $this->putJson("/api/inventory/units/{$unit}", ['imei' => $this->imei(34)])->assertStatus(422);
        $this->putJson("/api/inventory/units/{$unit}", ['color' => 'Starlight'])->assertOk();
    }

    public function test_suppliers_and_customers_can_be_managed_and_used_ones_are_protected(): void
    {
        $newSupplier = $this->postJson('/api/suppliers', ['name' => 'Guangzhou Parts', 'phone' => '0700'])
            ->assertCreated()->json('data.id');
        $this->putJson("/api/suppliers/{$newSupplier}", ['name' => 'Guangzhou Parts Ltd'])->assertOk();
        $this->deleteJson("/api/suppliers/{$newSupplier}")->assertNoContent();

        $this->stockPhone(1);
        $this->deleteJson("/api/suppliers/{$this->supplier->id}")->assertStatus(422);

        $this->postJson('/api/customers', ['name' => 'Juma', 'phone' => '0711', 'email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/customers', ['name' => 'Juma', 'phone' => '0711'])->assertCreated();
        $this->getJson('/api/customers?search=jum')->assertJsonCount(1, 'data');
    }

    public function test_other_users_stock_is_out_of_reach(): void
    {
        $other = $this->makeUser();
        $theirs = $this->makeProduct($other, ['name' => 'Secret phone']);
        $theirAccount = $this->makeAccount($other, ['opening_balance' => '5000000.00']);

        $this->getJson("/api/products/{$theirs->id}")->assertNotFound();
        $this->postJson('/api/inventory/purchases', [
            'product_id' => $theirs->id, 'purchase_date' => $this->daysAgo(1), 'account_id' => $this->shop->id,
            'units' => [['imei' => $this->imei(20), 'purchase_price' => '1000']],
        ])->assertStatus(422)->assertJsonValidationErrors('product_id');
        $this->postJson('/api/inventory/purchases', [
            'product_id' => $this->phoneProduct->id, 'purchase_date' => $this->daysAgo(1), 'account_id' => $theirAccount->id,
            'units' => [['imei' => $this->imei(21), 'purchase_price' => '1000']],
        ])->assertStatus(422)->assertJsonValidationErrors('account_id');
    }
}
