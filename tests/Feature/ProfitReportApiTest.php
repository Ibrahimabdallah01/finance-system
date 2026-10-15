<?php

namespace Tests\Feature;

use App\Enums\CategoryKind;
use App\Enums\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpShop;
use Tests\TestCase;

class ProfitReportApiTest extends TestCase
{
    use RefreshDatabase, SetsUpShop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
    }

    public function test_buying_stock_changes_cash_but_not_profit(): void
    {
        $this->stockPhone(1);
        $this->stockAccessories(10, '1000');

        $pnl = $this->profitLoss();

        $this->assertSame('0.00', $pnl['sales']['gross_sales']);
        $this->assertSame('0.00', $pnl['cost_of_goods_sold']['total']);
        $this->assertSame('0.00', $pnl['gross_profit']);
        $this->assertSame('0.00', $pnl['net_profit']);
        $this->assertSame('615000.00', $pnl['cash_view']['inventory_purchases_paid']);
        $this->assertSame('385000.00', $this->balanceOf($this->shop));
    }

    public function test_the_full_profit_and_loss_adds_up(): void
    {
        $phone = $this->stockPhone(1);          // cost 605,000
        $this->stockAccessories(10, '1000');    // cost 10,000

        $this->sellPhone($phone, '750000', [$this->pay('750000')])->assertCreated();
        $this->postJson('/api/sales', [
            'sale_date' => $this->daysAgo(4),
            'items' => [['product_id' => $this->accessory->id, 'quantity' => 3, 'unit_price' => '2000']],
            'payments' => [$this->pay('6000')],
        ])->assertCreated();

        $this->postJson('/api/inventory/write-offs', [
            'product_id' => $this->accessory->id, 'quantity' => 2, 'write_off_date' => $this->daysAgo(2), 'reason' => 'Damaged',
        ])->assertCreated();

        $rent = $this->makeCategory($this->user, ['name' => 'Rent', 'scope' => Scope::Business]);
        $this->postJson('/api/expenses', [
            'account_id' => $this->shop->id, 'category_id' => $rent->id, 'amount' => '20000',
            'transaction_date' => $this->daysAgo(3), 'description' => 'Shop rent', 'recipient' => 'Landlord',
        ])->assertCreated();

        $rebate = $this->makeCategory($this->user, ['name' => 'Supplier rebate', 'scope' => Scope::Business, 'kind' => CategoryKind::Income]);
        $this->postJson('/api/income', [
            'account_id' => $this->shop->id, 'category_id' => $rebate->id, 'amount' => '3000',
            'transaction_date' => $this->daysAgo(3), 'description' => 'Rebate', 'recipient' => 'Dubai Traders',
        ])->assertCreated();

        // Taking money home is neither an expense nor income.
        $this->postJson('/api/transfers', [
            'from_account_id' => $this->shop->id, 'to_account_id' => $this->wallet->id,
            'amount' => '50000', 'transaction_date' => $this->daysAgo(1),
        ])->assertCreated()->assertJsonPath('data.type', 'owner_withdrawal');

        $pnl = $this->profitLoss();

        $this->assertSame('756000.00', $pnl['sales']['gross_sales']);
        $this->assertSame('0.00', $pnl['sales']['returns']);
        $this->assertSame('756000.00', $pnl['sales']['net_sales']);
        $this->assertSame('608000.00', $pnl['cost_of_goods_sold']['total']);   // 605,000 + 3 x 1,000
        $this->assertSame('148000.00', $pnl['gross_profit']);
        $this->assertSame('2000.00', $pnl['inventory_write_offs']);
        $this->assertSame('20000.00', $pnl['operating_expenses']);
        $this->assertSame('3000.00', $pnl['other_business_income']);
        $this->assertSame('129000.00', $pnl['net_profit']);                    // 148,000 - 2,000 - 20,000 + 3,000

        $this->assertSame('756000.00', $pnl['cash_view']['customer_payments_received']);
        $this->assertSame('615000.00', $pnl['cash_view']['inventory_purchases_paid']);
        $this->assertSame('20000.00', $pnl['cash_view']['operating_expenses_paid']);
        $this->assertSame('50000.00', $pnl['cash_view']['owner_withdrawals']);
        $this->assertNotEmpty($pnl['notes']);

        // Cash on hand: 1,000,000 - 615,000 + 756,000 - 20,000 + 3,000 - 50,000
        $this->assertSame('1074000.00', $this->balanceOf($this->shop));
    }

    public function test_the_report_only_counts_the_chosen_period(): void
    {
        $this->sellPhone($this->stockPhone(1), '750000', [$this->pay('750000')], ['sale_date' => $this->daysAgo(45)])->assertCreated();

        $this->assertSame('0.00', $this->profitLoss()['sales']['gross_sales']); // last 30 days

        $wide = $this->getJson('/api/reports/profit-loss?from='.$this->daysAgo(60).'&to='.now()->toDateString())->json('data');
        $this->assertSame('750000.00', $wide['sales']['gross_sales']);
        $this->assertSame('145000.00', $wide['gross_profit']);
    }

    public function test_the_report_validates_its_dates(): void
    {
        $this->getJson('/api/reports/profit-loss?from='.$this->daysAgo(1).'&to='.$this->daysAgo(5))
            ->assertStatus(422)->assertJsonValidationErrors('to');

        $this->getJson('/api/reports/profit-loss')->assertOk()->assertJsonStructure(['data' => ['period', 'sales', 'gross_profit', 'net_profit']]);
    }

    public function test_the_inventory_summary_lists_products_sold(): void
    {
        $this->sellPhone($this->stockPhone(1), '750000', [$this->pay('750000')])->assertCreated();
        $this->stockAccessories(10, '1000');
        $this->postJson('/api/sales', [
            'sale_date' => $this->daysAgo(4),
            'items' => [['product_id' => $this->accessory->id, 'quantity' => 3, 'unit_price' => '2000']],
            'payments' => [$this->pay('6000')],
        ])->assertCreated();

        $sold = collect($this->getJson('/api/inventory/summary')->assertOk()->json('data.products_sold'))->keyBy('name');

        $this->assertSame(1, $sold['iPhone 13']['quantity_sold']);
        $this->assertSame('750000.00', $sold['iPhone 13']['sales_before_discount']);
        $this->assertSame(3, $sold['USB-C charger']['quantity_sold']);
        $this->assertSame('6000.00', $sold['USB-C charger']['sales_before_discount']);
    }
}
