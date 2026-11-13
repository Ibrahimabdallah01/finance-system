<?php

namespace Tests\Feature;

use App\Enums\CategoryKind;
use App\Enums\Scope;
use App\Models\Budget;
use App\Models\TransactionCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpShop;
use Tests\TestCase;

class BudgetApiTest extends TestCase
{
    use RefreshDatabase, SetsUpShop;

    private TransactionCategory $food;

    private TransactionCategory $groceries;

    private TransactionCategory $transport;

    private TransactionCategory $ads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->wallet->update(['opening_balance' => '10000000.00']);

        $this->food = $this->makeCategory($this->user, ['name' => 'Food']);
        $this->groceries = $this->makeCategory($this->user, ['name' => 'Groceries', 'parent_id' => $this->food->id]);
        $this->transport = $this->makeCategory($this->user, ['name' => 'Transport']);
        $this->ads = $this->makeCategory($this->user, ['name' => 'Ads', 'scope' => Scope::Business]);
    }

    private function budget(array $overrides = [])
    {
        return $this->postJson('/api/budgets', array_merge([
            'scope' => 'personal', 'category_id' => $this->food->id, 'name' => 'Food',
            'period' => 'monthly', 'amount' => '100000', 'start_date' => $this->daysAgo(200),
        ], $overrides));
    }

    private function spend(string $amount, ?TransactionCategory $category = null, ?string $date = null)
    {
        return $this->postJson('/api/expenses', [
            'account_id' => $this->wallet->id,
            'category_id' => ($category ?? $this->food)->id,
            'amount' => $amount,
            'transaction_date' => $date ?? now()->toDateString(),
            'description' => 'Spending',
        ]);
    }

    private function performance(?string $query = '')
    {
        return $this->getJson('/api/budgets'.$query)->assertOk();
    }

    public function test_a_category_budget_shows_spent_remaining_and_percent(): void
    {
        $this->budget()->assertCreated()->assertJsonPath('data.performance.spent', '0.00');

        $this->spend('30000')->assertCreated();

        $this->performance()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.performance.budget', '100000.00')
            ->assertJsonPath('data.0.performance.spent', '30000.00')
            ->assertJsonPath('data.0.performance.remaining', '70000.00')
            ->assertJsonPath('data.0.performance.percent_used', '30.00')
            ->assertJsonPath('data.0.performance.status', 'ok')
            ->assertJsonPath('data.0.performance.applies', true)
            ->assertJsonPath('data.0.category.name', 'Food');
    }

    public function test_budgets_only_count_real_expenses_of_their_own_scope(): void
    {
        $this->budget(['category_id' => null, 'name' => 'Personal spending', 'amount' => '200000'])->assertCreated();
        $this->budget(['scope' => 'business', 'category_id' => null, 'name' => 'Business costs', 'amount' => '500000'])->assertCreated();

        // Personal: 20,000 spent. Business: 50,000 spent.
        $this->spend('20000')->assertCreated();
        $this->postJson('/api/expenses', [
            'account_id' => $this->shop->id, 'category_id' => $this->ads->id, 'amount' => '50000',
            'transaction_date' => now()->toDateString(), 'description' => 'Facebook ads', 'recipient' => 'Meta',
        ])->assertCreated();

        // None of these are expenses: stock purchase, transfer, owner withdrawal.
        $this->stockPhone(1);
        $bank = $this->makeAccount($this->user, ['name' => 'Bank', 'scope' => Scope::Personal]);
        $this->postJson('/api/transfers', [
            'from_account_id' => $this->wallet->id, 'to_account_id' => $bank->id,
            'amount' => '10000', 'transaction_date' => now()->toDateString(),
        ])->assertCreated();
        $this->postJson('/api/transfers', [
            'from_account_id' => $this->shop->id, 'to_account_id' => $this->wallet->id,
            'amount' => '5000', 'transaction_date' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.type', 'owner_withdrawal');

        $rows = collect($this->performance()->json('data'))->keyBy('name');

        $this->assertSame('20000.00', $rows['Personal spending']['performance']['spent']);
        $this->assertSame('50000.00', $rows['Business costs']['performance']['spent']);
    }

    public function test_status_turns_to_warning_at_80_percent_and_over_at_100(): void
    {
        $this->budget(['category_id' => null, 'amount' => '10000'])->assertCreated();

        $this->spend('7999')->assertCreated();
        $this->performance()->assertJsonPath('data.0.performance.status', 'ok')
            ->assertJsonPath('data.0.performance.percent_used', '79.99');

        $this->spend('1')->assertCreated();
        $this->performance()->assertJsonPath('data.0.performance.status', 'warning')
            ->assertJsonPath('data.0.performance.percent_used', '80.00');

        $this->spend('2500')->assertCreated();
        $this->performance()->assertJsonPath('data.0.performance.status', 'over')
            ->assertJsonPath('data.0.performance.remaining', '-500.00');
    }

    public function test_a_parent_category_budget_includes_its_subcategories(): void
    {
        $this->budget()->assertCreated();

        $this->spend('10000', $this->groceries)->assertCreated();
        $this->spend('5000', $this->food)->assertCreated();
        $this->spend('7000', $this->transport)->assertCreated();

        $this->performance()->assertJsonPath('data.0.performance.spent', '15000.00');
    }

    public function test_voided_expenses_do_not_count(): void
    {
        $this->budget()->assertCreated();
        $id = $this->spend('40000')->json('data.id');
        $this->spend('1000')->assertCreated();

        $this->postJson("/api/transactions/{$id}/void", ['reason' => 'Duplicate'])->assertOk();

        $this->performance()->assertJsonPath('data.0.performance.spent', '1000.00');
    }

    public function test_any_period_can_be_viewed_by_picking_a_date(): void
    {
        $this->budget()->assertCreated();
        $lastMonth = Carbon::now()->subMonthNoOverflow()->startOfMonth()->addDays(1)->toDateString();

        $this->spend('12000', null, $lastMonth)->assertCreated();
        $this->spend('3000')->assertCreated();

        $this->performance()->assertJsonPath('data.0.performance.spent', '3000.00');
        $this->performance('?date='.$lastMonth)->assertJsonPath('data.0.performance.spent', '12000.00');
    }

    public function test_every_period_type_uses_calendar_boundaries(): void
    {
        foreach (['daily', 'weekly', 'monthly', 'yearly'] as $period) {
            $this->budget(['category_id' => null, 'name' => $period, 'period' => $period])->assertCreated();
        }

        $today = Carbon::today();
        $rows = collect($this->performance()->json('data'))->keyBy('name');

        $this->assertSame($today->toDateString(), $rows['daily']['performance']['period_start']);
        $this->assertSame($today->toDateString(), $rows['daily']['performance']['period_end']);
        $this->assertSame($today->copy()->startOfWeek(Carbon::MONDAY)->toDateString(), $rows['weekly']['performance']['period_start']);
        $this->assertSame($today->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(), $rows['weekly']['performance']['period_end']);
        $this->assertSame($today->copy()->startOfMonth()->toDateString(), $rows['monthly']['performance']['period_start']);
        $this->assertSame($today->copy()->endOfMonth()->toDateString(), $rows['monthly']['performance']['period_end']);
        $this->assertSame($today->copy()->startOfYear()->toDateString(), $rows['yearly']['performance']['period_start']);
        $this->assertSame($today->copy()->endOfYear()->toDateString(), $rows['yearly']['performance']['period_end']);
    }

    public function test_history_lists_the_last_periods_oldest_first(): void
    {
        $id = $this->budget()->json('data.id');
        $lastMonth = Carbon::now()->subMonthNoOverflow()->startOfMonth()->addDays(1)->toDateString();

        $this->spend('2000', null, $lastMonth)->assertCreated();
        $this->spend('1000')->assertCreated();

        $this->getJson("/api/budgets/{$id}/history?periods=3")
            ->assertOk()
            ->assertJsonCount(3, 'data.periods')
            ->assertJsonPath('data.periods.0.spent', '0.00')
            ->assertJsonPath('data.periods.1.spent', '2000.00')
            ->assertJsonPath('data.periods.2.spent', '1000.00')
            ->assertJsonPath('data.budget.name', 'Food');
    }

    public function test_budget_input_is_validated(): void
    {
        $this->budget()->assertCreated();

        // Only one active budget per scope, category and period.
        $this->budget(['name' => 'Again'])->assertStatus(422)->assertJsonValidationErrors('period');
        $this->budget(['name' => 'Inactive twin', 'is_active' => false])->assertCreated();

        $income = $this->makeCategory($this->user, ['name' => 'Salary', 'kind' => CategoryKind::Income]);
        $this->budget(['category_id' => $income->id, 'period' => 'weekly'])->assertStatus(422)->assertJsonValidationErrors('category_id');
        $this->budget(['category_id' => $this->ads->id, 'period' => 'weekly'])->assertStatus(422)->assertJsonValidationErrors('category_id');
        $this->budget(['amount' => '0', 'period' => 'weekly'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->budget(['period' => 'weekly', 'end_date' => $this->daysAgo(300)])->assertStatus(422)->assertJsonValidationErrors('end_date');
        $this->budget(['period' => 'fortnightly'])->assertStatus(422)->assertJsonValidationErrors('period');
    }

    public function test_a_budget_can_be_edited_and_deleted_but_not_moved(): void
    {
        $id = $this->budget()->json('data.id');
        $twin = $this->budget(['name' => 'Twin', 'is_active' => false])->json('data.id');

        $this->putJson("/api/budgets/{$id}", ['name' => 'Groceries and food', 'amount' => '150000', 'scope' => 'business', 'period' => 'yearly'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Groceries and food')
            ->assertJsonPath('data.amount', '150000.00')
            ->assertJsonPath('data.scope', 'personal')
            ->assertJsonPath('data.period', 'monthly');

        // Re-activating a twin would create a second active budget.
        $this->putJson("/api/budgets/{$twin}", ['is_active' => true])->assertStatus(422)->assertJsonValidationErrors('is_active');

        $this->deleteJson("/api/budgets/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('budgets', ['id' => $id]);
    }

    public function test_recording_an_expense_warns_when_a_budget_is_nearly_used(): void
    {
        $this->budget(['amount' => '10000'])->assertCreated();

        $this->spend('1000')->assertCreated()->assertJsonPath('meta.budget_warnings', []);

        $this->spend('7500')->assertCreated()
            ->assertJsonCount(1, 'meta.budget_warnings')
            ->assertJsonPath('meta.warnings', []);

        // A different category does not trigger it.
        $this->spend('50000', $this->transport)->assertCreated()->assertJsonPath('meta.budget_warnings', []);
    }

    public function test_other_users_budgets_are_invisible(): void
    {
        $other = $this->makeUser();
        $theirs = new Budget(['scope' => Scope::Personal, 'name' => 'Secret', 'period' => 'monthly', 'amount' => '1000', 'start_date' => $this->daysAgo(10)]);
        $theirs->user_id = $other->id;
        $theirs->save();

        $this->getJson('/api/budgets')->assertJsonCount(0, 'data');
        $this->getJson("/api/budgets/{$theirs->id}")->assertNotFound();
        $this->putJson("/api/budgets/{$theirs->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->deleteJson("/api/budgets/{$theirs->id}")->assertNotFound();
    }

    public function test_budgets_require_a_login(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/budgets')->assertUnauthorized();
    }
}
