<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\Scope;
use App\Models\Account;
use App\Models\SavingsGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpShop;
use Tests\TestCase;

class SavingsGoalApiTest extends TestCase
{
    use RefreshDatabase, SetsUpShop;

    private Account $cash;     // personal, 500,000

    private Account $savings;  // personal savings account

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();

        $this->cash = $this->makeAccount($this->user, ['name' => 'Personal cash', 'scope' => Scope::Personal, 'opening_balance' => '500000.00']);
        $this->savings = $this->makeAccount($this->user, ['name' => 'Emergency fund', 'scope' => Scope::Personal, 'type' => AccountType::Savings]);
    }

    private function goal(array $overrides = [])
    {
        return $this->postJson('/api/savings-goals', array_merge([
            'name' => 'Laptop', 'goal_type' => 'equipment', 'target_amount' => '400000', 'account_id' => $this->savings->id,
        ], $overrides));
    }

    private function save(int $goalId, string $amount, ?int $from = null)
    {
        return $this->postJson("/api/savings-goals/{$goalId}/contributions", array_filter([
            'amount' => $amount, 'contribution_date' => $this->daysAgo(1), 'from_account_id' => $from,
        ], fn ($v) => $v !== null));
    }

    private function take(int $goalId, string $amount, ?int $to = null)
    {
        return $this->postJson("/api/savings-goals/{$goalId}/withdrawals", array_filter([
            'amount' => $amount, 'contribution_date' => $this->daysAgo(1), 'to_account_id' => $to,
        ], fn ($v) => $v !== null));
    }

    public function test_a_goal_starts_empty_and_shows_what_to_save_each_month(): void
    {
        $this->goal(['target_date' => now()->addDays(120)->toDateString()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.account.name', 'Emergency fund')
            ->assertJsonPath('data.figures.saved', '0.00')
            ->assertJsonPath('data.figures.remaining', '400000.00')
            ->assertJsonPath('data.figures.percent_complete', '0.00')
            ->assertJsonPath('data.figures.is_complete', false)
            ->assertJsonPath('data.figures.months_left', 4)
            ->assertJsonPath('data.figures.required_per_month', '100000.00');
    }

    public function test_moving_money_to_savings_is_a_transfer_and_never_an_expense(): void
    {
        $id = $this->goal()->json('data.id');

        $this->save($id, '100000', $this->cash->id)
            ->assertCreated()
            ->assertJsonPath('data.figures.saved', '100000.00')
            ->assertJsonPath('data.figures.remaining', '300000.00')
            ->assertJsonPath('data.figures.percent_complete', '25.00')
            ->assertJsonPath('meta.warnings', []);

        $this->assertSame('400000.00', $this->balanceOf($this->cash));
        $this->assertSame('100000.00', $this->balanceOf($this->savings));

        $this->getJson('/api/transactions?type=expense')->assertJsonPath('meta.summary.by_type', []);
        $this->getJson('/api/transactions?type=transfer')->assertJsonPath('meta.summary.by_type.transfer.total', '100000.00');
    }

    public function test_money_already_in_the_savings_account_can_be_earmarked_without_moving_it(): void
    {
        $id = $this->goal()->json('data.id');

        $this->save($id, '50000')
            ->assertCreated()
            ->assertJsonPath('data.figures.saved', '50000.00')
            ->assertJsonPath('data.contributions.0.transaction_id', null);

        $this->assertSame('500000.00', $this->balanceOf($this->cash));
        $this->assertSame('0.00', $this->balanceOf($this->savings));
    }

    public function test_withdrawals_move_money_back_and_cannot_exceed_the_savings(): void
    {
        $id = $this->goal()->json('data.id');
        $this->save($id, '100000', $this->cash->id)->assertCreated();

        $this->take($id, '40000', $this->cash->id)
            ->assertCreated()
            ->assertJsonPath('data.figures.saved', '60000.00')
            ->assertJsonPath('data.contributions.0.amount', '-40000.00');

        $this->assertSame('440000.00', $this->balanceOf($this->cash));
        $this->assertSame('60000.00', $this->balanceOf($this->savings));

        $this->take($id, '70000', $this->cash->id)->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->assertSame('60000.00', $this->balanceOf($this->savings));
    }

    public function test_a_goal_is_achieved_when_the_target_is_reached_and_active_again_if_money_is_taken_out(): void
    {
        $id = $this->goal(['target_amount' => '100000'])->json('data.id');

        $this->save($id, '100000', $this->cash->id)
            ->assertJsonPath('data.status', 'achieved')
            ->assertJsonPath('data.figures.is_complete', true)
            ->assertJsonPath('data.figures.remaining', '0.00');

        $this->take($id, '10000', $this->cash->id)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.figures.is_complete', false);
    }

    public function test_voiding_the_transfer_removes_it_from_the_saved_amount(): void
    {
        $id = $this->goal()->json('data.id');
        $transactionId = $this->save($id, '100000', $this->cash->id)->json('data.contributions.0.transaction_id');

        $this->postJson("/api/transactions/{$transactionId}/void", ['reason' => 'Wrong account'])->assertOk();

        $this->getJson("/api/savings-goals/{$id}")
            ->assertJsonPath('data.figures.saved', '0.00')
            ->assertJsonPath('data.contributions.0.transaction_status', 'voided');
    }

    public function test_moving_money_needs_a_savings_account_and_an_active_goal(): void
    {
        $noAccount = $this->goal(['account_id' => null])->assertCreated()->json('data.id');
        $this->save($noAccount, '1000', $this->cash->id)->assertStatus(422)->assertJsonValidationErrors('from_account_id');

        $id = $this->goal(['name' => 'Rent'])->json('data.id');
        $this->putJson("/api/savings-goals/{$id}", ['status' => 'cancelled'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->save($id, '1000', $this->cash->id)->assertStatus(422)->assertJsonValidationErrors('goal');
    }

    public function test_business_money_moved_to_personal_savings_is_an_owner_withdrawal(): void
    {
        $id = $this->goal()->json('data.id');

        $this->save($id, '20000', $this->shop->id)->assertCreated();

        $this->getJson('/api/transactions?type=owner_withdrawal')
            ->assertJsonPath('meta.summary.by_type.owner_withdrawal.total', '20000.00');
        $this->getJson('/api/transactions?type=expense')->assertJsonPath('meta.summary.by_type', []);
    }

    public function test_goal_rules_for_editing_and_deleting(): void
    {
        $id = $this->goal()->json('data.id');
        $other = $this->makeAccount($this->user, ['name' => 'Other savings', 'scope' => Scope::Personal, 'type' => AccountType::Savings]);

        $this->putJson("/api/savings-goals/{$id}", ['account_id' => $other->id, 'name' => 'New laptop'])->assertOk();
        $this->save($id, '1000')->assertCreated();

        $this->putJson("/api/savings-goals/{$id}", ['account_id' => $this->savings->id])
            ->assertStatus(422)->assertJsonValidationErrors('account_id');
        $this->deleteJson("/api/savings-goals/{$id}")->assertStatus(422);

        $unused = $this->goal(['name' => 'Unused'])->json('data.id');
        $this->deleteJson("/api/savings-goals/{$unused}")->assertNoContent();
    }

    public function test_goal_input_is_validated(): void
    {
        $this->goal(['target_amount' => '0'])->assertStatus(422)->assertJsonValidationErrors('target_amount');
        $this->goal(['target_date' => $this->daysAgo(3)])->assertStatus(422)->assertJsonValidationErrors('target_date');
        $this->goal(['goal_type' => 'holiday'])->assertStatus(422)->assertJsonValidationErrors('goal_type');
        $this->goal(['account_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('account_id');

        $id = $this->goal()->json('data.id');
        $this->postJson("/api/savings-goals/{$id}/contributions", [
            'amount' => '1000', 'contribution_date' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('contribution_date');
    }

    public function test_the_list_shows_progress_and_other_users_goals_are_invisible(): void
    {
        $id = $this->goal()->json('data.id');
        $this->save($id, '100000', $this->cash->id)->assertCreated();

        $other = $this->makeUser();
        $theirs = new SavingsGoal(['name' => 'Secret', 'goal_type' => 'other', 'target_amount' => '1000']);
        $theirs->user_id = $other->id;
        $theirs->save();

        $this->getJson('/api/savings-goals')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.figures.saved', '100000.00');

        $this->getJson("/api/savings-goals/{$theirs->id}")->assertNotFound();
        $this->save($theirs->id, '1000')->assertNotFound();
    }
}
