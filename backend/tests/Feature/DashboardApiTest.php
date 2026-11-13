<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\CategoryKind;
use App\Enums\Scope;
use App\Models\Account;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpShop;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase, SetsUpShop;

    private Account $savings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();

        $this->wallet->update(['opening_balance' => '200000.00']);
        $this->savings = $this->makeAccount($this->user, ['name' => 'Savings', 'scope' => Scope::Personal, 'type' => AccountType::Savings]);
    }

    private function dashboard(?string $from = null, ?string $to = null)
    {
        return $this->getJson('/api/dashboard?from='.($from ?? $this->daysAgo(40)).'&to='.($to ?? now()->toDateString()))->assertOk();
    }

    /** A realistic month: sales, stock, expenses, savings, a loan and a customer who still owes money. */
    private function seedMonth(): void
    {
        $food = $this->makeCategory($this->user, ['name' => 'Food']);
        $commission = $this->makeCategory($this->user, ['name' => 'Commissions', 'kind' => CategoryKind::Income]);
        $rent = $this->makeCategory($this->user, ['name' => 'Rent', 'scope' => Scope::Business]);

        $this->sellPhone($this->stockPhone(1), '750000', [$this->pay('750000')])->assertCreated();   // cost 605,000
        $this->sellPhone($this->stockPhone(2, '300000', '0'), '400000', [$this->pay('100000')])->assertCreated(); // 300,000 owed

        $this->postJson('/api/income', [
            'account_id' => $this->wallet->id, 'category_id' => $commission->id, 'amount' => '40000',
            'transaction_date' => $this->daysAgo(3), 'description' => 'Brokerage commission',
        ])->assertCreated();
        $this->postJson('/api/expenses', [
            'account_id' => $this->wallet->id, 'category_id' => $food->id, 'amount' => '15000',
            'transaction_date' => now()->toDateString(), 'description' => 'Groceries',
        ])->assertCreated();
        $this->postJson('/api/expenses', [
            'account_id' => $this->shop->id, 'category_id' => $rent->id, 'amount' => '20000',
            'transaction_date' => $this->daysAgo(2), 'description' => 'Shop rent', 'recipient' => 'Landlord',
        ])->assertCreated();

        // Moves between own accounts: never income or expense.
        $this->postJson('/api/transfers', [
            'from_account_id' => $this->shop->id, 'to_account_id' => $this->wallet->id,
            'amount' => '50000', 'transaction_date' => $this->daysAgo(2),
        ])->assertCreated();

        // Savings goal funded from the wallet.
        $goal = $this->postJson('/api/savings-goals', [
            'name' => 'Laptop', 'goal_type' => 'equipment', 'target_amount' => '400000', 'account_id' => $this->savings->id,
        ])->json('data.id');
        $this->postJson("/api/savings-goals/{$goal}/contributions", [
            'amount' => '25000', 'contribution_date' => $this->daysAgo(1), 'from_account_id' => $this->wallet->id,
        ])->assertCreated();

        // A personal loan received: a liability, not income.
        $this->postJson('/api/debts', [
            'scope' => 'personal', 'direction' => 'payable', 'source' => 'loan', 'party_name' => 'Mama',
            'original_amount' => '100000', 'issued_on' => $this->daysAgo(4), 'account_id' => $this->wallet->id,
        ])->assertCreated();

        $this->postJson('/api/budgets', [
            'scope' => 'personal', 'category_id' => $food->id, 'name' => 'Food', 'period' => 'monthly',
            'amount' => '100000', 'start_date' => $this->daysAgo(100),
        ])->assertCreated();
    }

    public function test_the_cards_add_up_from_the_saved_records(): void
    {
        $this->seedMonth();

        $cards = $this->dashboard()->json('data.cards');

        // Shop 875,000 + wallet 350,000 + savings 25,000
        $this->assertSame('1250000.00', $cards['cash']['total']);
        $this->assertSame('875000.00', $cards['cash']['business']);
        $this->assertSame('375000.00', $cards['cash']['personal']);
        $this->assertCount(3, $cards['cash']['accounts']);

        $this->assertSame('1150000.00', $cards['sales_revenue']);   // 750,000 + 400,000
        $this->assertSame('245000.00', $cards['gross_profit']);     // minus cost 905,000
        $this->assertSame('225000.00', $cards['net_profit']);       // minus rent 20,000

        $this->assertSame('40000.00', $cards['personal_income']);   // loan and transfers are not income
        $this->assertSame('15000.00', $cards['personal_expenses']);
        $this->assertSame('20000.00', $cards['business_expenses']);

        $this->assertSame('25000.00', $cards['personal_savings']['accounts_total']);
        $this->assertSame('25000.00', $cards['personal_savings']['goals_saved']);

        $this->assertSame('300000.00', $cards['owed_to_me']);
        $this->assertSame('100000.00', $cards['i_owe']);

        $this->assertSame('1250000.00', $cards['net_position']['assets']['cash']);
        $this->assertSame('0.00', $cards['net_position']['assets']['inventory_at_cost']);
        $this->assertSame('300000.00', $cards['net_position']['assets']['receivables']);
        $this->assertSame('1550000.00', $cards['net_position']['assets']['total']);
        $this->assertSame('100000.00', $cards['net_position']['liabilities']['total']);
        $this->assertSame('1450000.00', $cards['net_position']['net']);

        $this->assertSame(1, $cards['budget_usage']['count']);
        $this->assertSame(0, $cards['budget_usage']['over']);
        $this->assertSame('15.00', $cards['budget_usage']['highest']['percent_used']);
    }

    public function test_stock_on_the_shelf_counts_as_an_asset_at_cost(): void
    {
        $this->stockPhone(1); // 605,000 bought, nothing sold

        $cards = $this->dashboard()->json('data.cards');

        $this->assertSame('605000.00', $cards['net_position']['assets']['inventory_at_cost']);
        $this->assertSame('0.00', $cards['net_profit']);           // buying stock is not a cost until it is sold
        $this->assertSame('395000.00', $cards['cash']['business']); // but the cash is gone
        $this->assertSame('595000.00', $cards['cash']['total']);              // 395,000 shop + 200,000 wallet
        $this->assertSame('1200000.00', $cards['net_position']['net']);       // cash 595,000 + stock 605,000
    }

    public function test_the_charts_use_the_same_records(): void
    {
        $this->seedMonth();

        $charts = $this->dashboard()->json('data.charts');

        $this->assertCount(1, $charts['personal_expense_categories']);
        $this->assertSame('Food', $charts['personal_expense_categories'][0]['name']);
        $this->assertSame('15000.00', $charts['personal_expense_categories'][0]['total']);
        $this->assertSame('100.00', $charts['personal_expense_categories'][0]['percent']);
        $this->assertSame('Rent', $charts['business_expense_categories'][0]['name']);
        $this->assertSame('20000.00', $charts['business_expense_categories'][0]['total']);

        $months = collect($charts['monthly']);
        $this->assertSame('40000.00', Money::sum($months->pluck('personal_income')));
        $this->assertSame('15000.00', Money::sum($months->pluck('personal_expenses')));
        $this->assertSame('1150000.00', Money::sum($months->pluck('business_sales')));
        $this->assertSame('905000.00', Money::sum($months->pluck('business_cogs')));
        $this->assertSame('20000.00', Money::sum($months->pluck('business_expenses')));
        $this->assertSame('225000.00', Money::sum($months->pluck('business_net_profit')));

        $this->assertSame('Food', $charts['budget_vs_actual'][0]['name']);
        $this->assertSame('15000.00', $charts['budget_vs_actual'][0]['spent']);
    }

    public function test_cash_flow_leaves_out_moves_between_own_accounts_and_ends_at_the_real_balance(): void
    {
        $this->seedMonth();

        $flow = $this->dashboard()->json('data.charts.cash_flow');
        $points = collect($flow['points']);

        $this->assertSame('day', $flow['granularity']);
        $this->assertCount(41, $points);
        $this->assertSame(now()->toDateString(), $points->last()['label']);
        $this->assertSame('1250000.00', $points->last()['closing_balance']);

        // The 50,000 owner withdrawal and the 25,000 savings transfer are not money in or out.
        // In: sales 750,000 + deposit 100,000 + commission 40,000 + loan 100,000. Out: stock 905,000 + food 15,000 + rent 20,000.
        $moneyIn = Money::sum($points->pluck('money_in'));
        $moneyOut = Money::sum($points->pluck('money_out'));
        $this->assertSame('990000.00', $moneyIn);
        $this->assertSame('940000.00', $moneyOut);
        $this->assertSame('50000.00', Money::sum($points->pluck('net')));
        $this->assertSame('1250000.00', Money::add('1200000.00', Money::sum($points->pluck('net')))); // opening 1,200,000 + net
    }

    public function test_a_long_period_switches_to_monthly_cash_flow(): void
    {
        $this->stockPhone(1);

        $flow = $this->dashboard($this->daysAgo(200))->json('data.charts.cash_flow');

        $this->assertSame('month', $flow['granularity']);
        $this->assertGreaterThanOrEqual(7, count($flow['points']));
        $this->assertSame('595000.00', collect($flow['points'])->last()['closing_balance']); // cash only, stock is not cash
    }

    public function test_an_empty_system_returns_zeros_and_empty_charts(): void
    {
        $this->app['auth']->forgetGuards();
        $fresh = $this->makeUser();
        $this->actingAs($fresh);

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertSame('0.00', $data['cards']['cash']['total']);
        $this->assertSame('0.00', $data['cards']['net_profit']);
        $this->assertSame('0.00', $data['cards']['net_position']['net']);
        $this->assertSame([], $data['charts']['personal_expense_categories']);
        $this->assertSame([], $data['charts']['budget_vs_actual']);
        $this->assertNull($data['cards']['budget_usage']['highest']);
        $this->assertNotEmpty($data['notes']);
    }

    public function test_the_dashboard_only_shows_the_owners_records_and_validates_dates(): void
    {
        $other = $this->makeUser();
        $this->makeAccount($other, ['opening_balance' => '9999999.00']);

        $this->assertSame('1200000.00', $this->dashboard()->json('data.cards.cash.total'));

        $this->getJson('/api/dashboard?from='.$this->daysAgo(1).'&to='.$this->daysAgo(5))
            ->assertStatus(422)->assertJsonValidationErrors('to');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }
}
