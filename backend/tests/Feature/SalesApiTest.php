<?php

namespace Tests\Feature;

use App\Models\InventoryUnit;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpShop;
use Tests\TestCase;

class SalesApiTest extends TestCase
{
    use RefreshDatabase, SetsUpShop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
    }

    private function unitStatus(int $unitId): string
    {
        return $this->getJson("/api/inventory/units/{$unitId}")->json('data.status');
    }

    private function sale(int $id)
    {
        return $this->getJson("/api/sales/{$id}")->assertOk();
    }

    public function test_a_fully_paid_phone_sale_shows_revenue_cost_and_profit(): void
    {
        $unit = $this->stockPhone(1); // cost 600,000 + 5,000

        $this->sellPhone($unit, '750000', [$this->pay('750000')])
            ->assertCreated()
            ->assertJsonPath('data.sale_number', 'S-00001')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.figures.total', '750000.00')
            ->assertJsonPath('data.figures.net_revenue', '750000.00')
            ->assertJsonPath('data.figures.cogs', '605000.00')
            ->assertJsonPath('data.figures.gross_profit', '145000.00')
            ->assertJsonPath('data.figures.net_profit', '145000.00')
            ->assertJsonPath('data.figures.amount_paid', '750000.00')
            ->assertJsonPath('data.figures.outstanding_balance', '0.00')
            ->assertJsonPath('data.figures.payment_status', 'paid')
            ->assertJsonPath('data.items.0.unit.imei', $this->imei(1))
            ->assertJsonCount(1, 'data.payments');

        $this->assertSame('1145000.00', $this->balanceOf($this->shop));
        $this->assertSame('sold', $this->unitStatus($unit));
        $this->getJson("/api/inventory/units/{$unit}")
            ->assertJsonPath('data.sale.sale_number', 'S-00001')
            ->assertJsonPath('data.sale.gross_profit', '145000.00');
    }

    public function test_a_deposit_then_later_payments_never_change_the_revenue(): void
    {
        $unit = $this->stockPhone(1);
        $id = $this->sellPhone($unit, '750000', [$this->pay('200000')])
            ->assertCreated()
            ->assertJsonPath('data.figures.amount_paid', '200000.00')
            ->assertJsonPath('data.figures.outstanding_balance', '550000.00')
            ->assertJsonPath('data.figures.payment_status', 'partial')
            ->json('data.id');

        $pnl = $this->profitLoss();
        $this->assertSame('750000.00', $pnl['sales']['gross_sales']);          // revenue at the sale
        $this->assertSame('200000.00', $pnl['cash_view']['customer_payments_received']); // cash so far

        $this->getJson("/api/customers/{$this->customer->id}")->assertJsonPath('data.outstanding_balance', '550000.00');

        $this->postJson("/api/sales/{$id}/payments", array_merge($this->pay('550000'), ['transaction_date' => $this->daysAgo(3)]))
            ->assertCreated()
            ->assertJsonPath('data.figures.payment_status', 'paid')
            ->assertJsonPath('data.figures.outstanding_balance', '0.00')
            ->assertJsonPath('data.figures.total', '750000.00');

        $this->assertSame('750000.00', $this->profitLoss()['sales']['gross_sales']);
        $this->assertSame('750000.00', $this->profitLoss()['cash_view']['customer_payments_received']);
    }

    public function test_payments_cannot_exceed_the_balance_or_predate_the_sale(): void
    {
        $id = $this->sellPhone($this->stockPhone(1), '750000', [$this->pay('700000')])->json('data.id');

        $this->postJson("/api/sales/{$id}/payments", array_merge($this->pay('50001'), ['transaction_date' => $this->daysAgo(3)]))
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->postJson("/api/sales/{$id}/payments", array_merge($this->pay('50000'), ['transaction_date' => $this->daysAgo(8)]))
            ->assertStatus(422)->assertJsonValidationErrors('transaction_date');

        $this->postJson("/api/sales/{$id}/payments", array_merge(['account_id' => $this->wallet->id, 'amount' => '50000'], ['transaction_date' => $this->daysAgo(3)]))
            ->assertStatus(422)->assertJsonValidationErrors('account_id');

        $this->sale($id)->assertJsonPath('data.figures.outstanding_balance', '50000.00');
    }

    public function test_an_unpaid_sale_needs_a_customer_but_a_paid_walk_in_does_not(): void
    {
        $unit = $this->stockPhone(1);

        $this->sellPhone($unit, '750000', [], ['customer_id' => null])
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');

        $this->sellPhone($unit, '750000', [$this->pay('750000')], ['customer_id' => null])
            ->assertCreated()->assertJsonPath('data.customer', null);

        $this->sellPhone($this->stockPhone(2), '750000', [$this->pay('800000')])
            ->assertStatus(422)->assertJsonValidationErrors('payments');
    }

    public function test_the_same_phone_cannot_be_sold_twice_and_units_must_match_the_product(): void
    {
        $unit = $this->stockPhone(1);
        $this->sellPhone($unit, '750000', [$this->pay('750000')])->assertCreated();

        $this->sellPhone($unit, '750000', [$this->pay('750000')])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.inventory_unit_id');

        $other = $this->stockPhone(2);
        $this->postJson('/api/sales', [
            'customer_id' => $this->customer->id, 'sale_date' => $this->daysAgo(1),
            'items' => [['product_id' => $this->accessory->id, 'inventory_unit_id' => $other, 'quantity' => 1, 'unit_price' => '1000']],
        ])->assertStatus(422);
    }

    public function test_counted_items_reduce_stock_at_average_cost_and_cannot_be_oversold(): void
    {
        $this->stockAccessories(10, '1000');

        $this->postJson('/api/sales', [
            'sale_date' => $this->daysAgo(2),
            'items' => [['product_id' => $this->accessory->id, 'quantity' => 3, 'unit_price' => '2000']],
            'payments' => [$this->pay('6000')],
        ])
            ->assertCreated()
            ->assertJsonPath('data.figures.cogs', '3000.00')
            ->assertJsonPath('data.figures.gross_profit', '3000.00');

        $this->getJson("/api/products/{$this->accessory->id}")->assertJsonPath('data.stock.quantity', 7);

        $this->postJson('/api/sales', [
            'sale_date' => $this->daysAgo(2),
            'items' => [['product_id' => $this->accessory->id, 'quantity' => 8, 'unit_price' => '2000']],
            'payments' => [$this->pay('16000')],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');

        $this->getJson("/api/products/{$this->accessory->id}")->assertJsonPath('data.stock.quantity', 7);
    }

    public function test_a_partial_return_of_counted_items_restocks_and_reverses_part_of_the_revenue(): void
    {
        $this->stockAccessories(10, '1000');
        $sale = $this->postJson('/api/sales', [
            'sale_date' => $this->daysAgo(4),
            'items' => [['product_id' => $this->accessory->id, 'quantity' => 3, 'unit_price' => '2000']],
            'payments' => [$this->pay('6000')],
        ])->assertCreated();

        $itemId = $sale->json('data.items.0.id');

        $this->postJson('/api/sales/'.$sale->json('data.id').'/returns', [
            'sale_item_id' => $itemId, 'quantity' => 1, 'return_date' => $this->daysAgo(2),
        ])
            ->assertCreated()
            ->assertJsonPath('data.figures.returned_revenue', '2000.00')
            ->assertJsonPath('data.figures.net_revenue', '4000.00')
            ->assertJsonPath('data.figures.cogs', '2000.00')
            ->assertJsonPath('data.figures.gross_profit', '2000.00')
            ->assertJsonPath('data.figures.refund_due', '2000.00')
            ->assertJsonPath('data.items.0.returned_quantity', 1);

        $this->getJson("/api/products/{$this->accessory->id}")
            ->assertJsonPath('data.stock.quantity', 8)
            ->assertJsonPath('data.average_cost', '1000.00');
    }

    public function test_a_directly_sold_item_records_its_cost_once_and_cannot_be_cancelled(): void
    {
        $payload = [
            'sale_date' => $this->daysAgo(2), 'cost_account_id' => $this->shop->id,
            'items' => [['product_id' => $this->directProduct->id, 'quantity' => 2, 'unit_price' => '6000', 'unit_cost' => '4000']],
            'payments' => [$this->pay('12000')],
        ];

        $id = $this->postJson('/api/sales', $payload)
            ->assertCreated()
            ->assertJsonPath('data.figures.total', '12000.00')
            ->assertJsonPath('data.figures.cogs', '8000.00')
            ->assertJsonPath('data.figures.gross_profit', '4000.00')
            ->assertJsonCount(1, 'data.costs')
            ->json('data.id');

        $this->assertSame('1004000.00', $this->balanceOf($this->shop));

        // Not counted as an operating expense as well: the cost is already in cost of goods sold.
        $pnl = $this->profitLoss();
        $this->assertSame('0.00', $pnl['operating_expenses']);
        $this->assertSame('4000.00', $pnl['net_profit']);

        $this->postJson("/api/sales/{$id}/cancel", ['reason' => 'Changed mind'])->assertStatus(422)->assertJsonValidationErrors('sale');

        unset($payload['cost_account_id']);
        $this->postJson('/api/sales', $payload)->assertStatus(422)->assertJsonValidationErrors('cost_account_id');
    }

    public function test_a_discount_lowers_the_agreed_price_and_the_profit(): void
    {
        $this->sellPhone($this->stockPhone(1), '750000', [$this->pay('700000')], ['discount_amount' => '50000'])
            ->assertCreated()
            ->assertJsonPath('data.figures.subtotal', '750000.00')
            ->assertJsonPath('data.figures.discount', '50000.00')
            ->assertJsonPath('data.figures.total', '700000.00')
            ->assertJsonPath('data.figures.gross_profit', '95000.00')
            ->assertJsonPath('data.figures.payment_status', 'paid');

        $this->sellPhone($this->stockPhone(2), '750000', [], ['discount_amount' => '800000'])
            ->assertStatus(422)->assertJsonValidationErrors('discount_amount');
    }

    public function test_direct_selling_costs_reduce_net_profit_and_are_counted_once(): void
    {
        $id = $this->sellPhone($this->stockPhone(1), '750000', [$this->pay('750000')])->json('data.id');
        $cost = [
            'account_id' => $this->shop->id, 'category_id' => $this->delivery->id, 'amount' => '5000',
            'transaction_date' => $this->daysAgo(4), 'description' => 'Delivery to customer', 'recipient' => 'Boda rider',
        ];

        $this->postJson("/api/sales/{$id}/costs", $cost)
            ->assertCreated()
            ->assertJsonPath('data.figures.direct_costs', '5000.00')
            ->assertJsonPath('data.figures.gross_profit', '145000.00')
            ->assertJsonPath('data.figures.net_profit', '140000.00');

        $pnl = $this->profitLoss();
        $this->assertSame('5000.00', $pnl['operating_expenses']);
        $this->assertSame('140000.00', $pnl['net_profit']);

        unset($cost['recipient']);
        $this->postJson("/api/sales/{$id}/costs", $cost)->assertStatus(422)->assertJsonValidationErrors('recipient');
        $this->postJson("/api/sales/{$id}/costs", array_merge($cost, ['recipient' => 'X', 'account_id' => $this->wallet->id]))
            ->assertStatus(422)->assertJsonValidationErrors('account_id');
    }

    public function test_returning_a_phone_to_stock_reverses_revenue_and_cost_and_needs_a_refund(): void
    {
        $unit = $this->stockPhone(1);
        $id = $this->sellPhone($unit, '750000', [$this->pay('750000')])->json('data.id');
        $itemId = $this->sale($id)->json('data.items.0.id');

        $this->postJson("/api/sales/{$id}/returns", ['sale_item_id' => $itemId, 'return_date' => $this->daysAgo(2)])
            ->assertCreated()
            ->assertJsonPath('data.figures.returned_revenue', '750000.00')
            ->assertJsonPath('data.figures.net_revenue', '0.00')
            ->assertJsonPath('data.figures.cogs', '0.00')
            ->assertJsonPath('data.figures.gross_profit', '0.00')
            ->assertJsonPath('data.figures.refund_due', '750000.00')
            ->assertJsonPath('data.figures.payment_status', 'refund_due');

        $this->assertSame('in_stock', $this->unitStatus($unit));

        $this->postJson("/api/sales/{$id}/refunds", array_merge($this->pay('750000'), ['transaction_date' => $this->daysAgo(1)]))
            ->assertCreated()
            ->assertJsonPath('data.figures.amount_paid', '0.00')
            ->assertJsonPath('data.figures.refund_due', '0.00')
            ->assertJsonPath('data.figures.payment_status', 'returned');

        $this->assertSame('395000.00', $this->balanceOf($this->shop));

        $pnl = $this->profitLoss();
        $this->assertSame('0.00', $pnl['sales']['net_sales']);
        $this->assertSame('0.00', $pnl['cost_of_goods_sold']['total']);
        $this->assertSame('0.00', $pnl['net_profit']);

        // The same phone can be sold again, and the item cannot be returned twice.
        $this->sellPhone($unit, '760000', [$this->pay('760000')])->assertCreated()->assertJsonPath('data.sale_number', 'S-00002');
        $this->postJson("/api/sales/{$id}/returns", ['sale_item_id' => $itemId, 'return_date' => $this->daysAgo(1)])
            ->assertStatus(422)->assertJsonValidationErrors('quantity');
    }

    public function test_a_return_that_is_not_restocked_keeps_the_cost_as_a_loss_without_double_counting(): void
    {
        $unit = $this->stockPhone(1);
        $id = $this->sellPhone($unit, '750000', [$this->pay('750000')])->json('data.id');
        $itemId = $this->sale($id)->json('data.items.0.id');

        $this->postJson("/api/sales/{$id}/returns", [
            'sale_item_id' => $itemId, 'return_date' => $this->daysAgo(2), 'restock' => false, 'reason' => 'Water damage',
        ])
            ->assertCreated()
            ->assertJsonPath('data.figures.net_revenue', '0.00')
            ->assertJsonPath('data.figures.cogs', '605000.00')
            ->assertJsonPath('data.figures.gross_profit', '-605000.00');

        $this->assertSame('written_off', $this->unitStatus($unit));

        $pnl = $this->profitLoss();
        $this->assertSame('0.00', $pnl['inventory_write_offs']);   // the loss is already in cost of goods sold
        $this->assertSame('-605000.00', $pnl['net_profit']);
    }

    public function test_returns_and_refunds_are_limited(): void
    {
        $id = $this->sellPhone($this->stockPhone(1), '750000', [$this->pay('200000')])->json('data.id');
        $itemId = $this->sale($id)->json('data.items.0.id');

        $this->postJson("/api/sales/{$id}/refunds", array_merge($this->pay('300000'), ['transaction_date' => $this->daysAgo(2)]))
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->postJson("/api/sales/{$id}/returns", [
            'sale_item_id' => $itemId, 'return_date' => $this->daysAgo(2), 'return_amount' => '800000',
        ])->assertStatus(422)->assertJsonValidationErrors('return_amount');

        $this->postJson("/api/sales/{$id}/returns", ['sale_item_id' => 999999, 'return_date' => $this->daysAgo(2)])
            ->assertStatus(422)->assertJsonValidationErrors('sale_item_id');
    }

    public function test_a_cancelled_sale_disappears_from_profit_and_the_phone_goes_back_to_stock(): void
    {
        $unit = $this->stockPhone(1);
        $id = $this->sellPhone($unit, '750000')->assertCreated()
            ->assertJsonPath('data.figures.payment_status', 'unpaid')->json('data.id');

        $this->postJson("/api/sales/{$id}/cancel", ['reason' => 'Customer changed mind'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.figures.payment_status', 'cancelled')
            ->assertJsonPath('data.figures.net_revenue', '0.00')
            ->assertJsonPath('data.figures.cogs', '0.00');

        $this->assertSame('in_stock', $this->unitStatus($unit));
        $this->assertSame('0.00', $this->profitLoss()['sales']['gross_sales']);

        $this->postJson("/api/sales/{$id}/cancel", ['reason' => 'Again'])->assertStatus(422);
        $this->postJson("/api/sales/{$id}/payments", array_merge($this->pay('1000'), ['transaction_date' => $this->daysAgo(1)]))
            ->assertStatus(422);

        $this->sellPhone($unit, '750000', [$this->pay('750000')])->assertCreated()->assertJsonPath('data.sale_number', 'S-00002');
    }

    public function test_a_sale_with_customer_money_can_only_be_cancelled_after_refunding(): void
    {
        $id = $this->sellPhone($this->stockPhone(1), '750000', [$this->pay('100000')])->json('data.id');

        $this->postJson("/api/sales/{$id}/cancel", ['reason' => 'Changed mind'])
            ->assertStatus(422)->assertJsonValidationErrors('sale');

        $this->postJson("/api/sales/{$id}/refunds", array_merge($this->pay('100000'), ['transaction_date' => $this->daysAgo(2)]))->assertCreated();
        $this->postJson("/api/sales/{$id}/cancel", ['reason' => 'Changed mind'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_a_sale_with_returns_cannot_be_cancelled(): void
    {
        $id = $this->sellPhone($this->stockPhone(1), '750000', [$this->pay('750000')])->json('data.id');
        $itemId = $this->sale($id)->json('data.items.0.id');
        $this->postJson("/api/sales/{$id}/returns", ['sale_item_id' => $itemId, 'return_date' => $this->daysAgo(2)])->assertCreated();

        $this->postJson("/api/sales/{$id}/cancel", ['reason' => 'Too late'])->assertStatus(422)->assertJsonValidationErrors('sale');
    }

    public function test_voiding_a_customer_payment_reopens_the_balance_but_a_sale_cost_payment_cannot_be_voided(): void
    {
        $id = $this->sellPhone($this->stockPhone(1), '750000', [$this->pay('750000')])->json('data.id');
        $paymentId = $this->sale($id)->json('data.payments.0.id');

        $this->postJson("/api/transactions/{$paymentId}/void", ['reason' => 'Payment bounced'])->assertOk();
        $this->sale($id)
            ->assertJsonPath('data.figures.outstanding_balance', '750000.00')
            ->assertJsonPath('data.figures.payment_status', 'unpaid')
            ->assertJsonPath('data.payments.0.status', 'voided');

        $direct = $this->postJson('/api/sales', [
            'sale_date' => $this->daysAgo(2), 'cost_account_id' => $this->shop->id,
            'items' => [['product_id' => $this->directProduct->id, 'quantity' => 1, 'unit_price' => '6000', 'unit_cost' => '4000']],
            'payments' => [$this->pay('6000')],
        ])->assertCreated();

        $this->postJson('/api/transactions/'.$direct->json('data.costs.0.id').'/void', ['reason' => 'Mistake'])
            ->assertStatus(422)->assertJsonValidationErrors('transaction');
    }

    public function test_sales_can_be_listed_and_filtered(): void
    {
        $first = $this->sellPhone($this->stockPhone(1), '750000', [$this->pay('750000')])->json('data.id');
        $this->sellPhone($this->stockPhone(2), '760000')->assertCreated();
        $this->postJson("/api/sales/{$first}/cancel", ['reason' => 'x'])->assertStatus(422); // paid sale

        $this->getJson('/api/sales')->assertJsonCount(2, 'data');
        $this->getJson('/api/sales?search=S-00002')->assertJsonCount(1, 'data')->assertJsonPath('data.0.sale_number', 'S-00002');
        $this->getJson('/api/sales?search=asha')->assertJsonCount(2, 'data');
        $this->getJson("/api/sales?customer_id={$this->customer->id}&status=completed")->assertJsonCount(2, 'data');
        $this->getJson('/api/sales?status=cancelled')->assertJsonCount(0, 'data');
    }

    public function test_other_users_sales_and_stock_are_out_of_reach(): void
    {
        $other = $this->makeUser();
        $theirProduct = $this->makeProduct($other, ['name' => 'Secret phone']);

        $theirUnit = new InventoryUnit(['imei' => $this->imei(900), 'purchase_date' => $this->daysAgo(5), 'purchase_price' => '1000', 'additional_costs' => '0']);
        $theirUnit->forceFill(['user_id' => $other->id, 'product_id' => $theirProduct->id])->save();

        $theirSale = new Sale(['sale_date' => $this->daysAgo(3), 'discount_amount' => '0', 'total_amount' => '5000']);
        $theirSale->forceFill(['user_id' => $other->id, 'status' => 'completed', 'sale_number' => 'S-00001'])->save();

        $this->getJson("/api/sales/{$theirSale->id}")->assertNotFound();
        $this->postJson("/api/sales/{$theirSale->id}/payments", array_merge($this->pay('100'), ['transaction_date' => $this->daysAgo(1)]))->assertNotFound();
        $this->postJson("/api/sales/{$theirSale->id}/cancel", ['reason' => 'Nope'])->assertNotFound();
        $this->getJson('/api/sales')->assertJsonCount(0, 'data');

        $this->sellPhone($theirUnit->id, '1000', [$this->pay('1000')])->assertStatus(422)->assertJsonValidationErrors('items.0.inventory_unit_id');

        $this->postJson('/api/sales', [
            'sale_date' => $this->daysAgo(1),
            'items' => [['product_id' => $theirProduct->id, 'quantity' => 1, 'unit_price' => '1000']],
            'payments' => [$this->pay('1000')],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');

        $this->sellPhone($this->stockPhone(1), '750000', [], ['customer_id' => $this->makeCustomer($other, 'Theirs')->id])
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');
    }

    public function test_everything_requires_a_login(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/sales')->assertUnauthorized();
        $this->getJson('/api/products')->assertUnauthorized();
        $this->getJson('/api/reports/profit-loss')->assertUnauthorized();
        $this->postJson('/api/inventory/purchases', [])->assertUnauthorized();
    }
}
