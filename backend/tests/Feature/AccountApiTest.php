<?php

namespace Tests\Feature;

use App\Enums\Scope;
use App\Enums\TransactionType;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFinanceData;
use Tests\TestCase;

class AccountApiTest extends TestCase
{
    use CreatesFinanceData, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->makeUser();
        $this->actingAs($this->user);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'scope' => 'business',
            'name' => 'Cash drawer',
            'type' => 'cash',
            'opening_balance' => '250000',
            'opening_balance_date' => $this->daysAgo(30),
        ], $overrides);
    }

    public function test_requires_login(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/accounts')->assertUnauthorized();
    }

    public function test_an_account_can_be_created_with_its_opening_balance(): void
    {
        $this->postJson('/api/accounts', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Cash drawer')
            ->assertJsonPath('data.scope', 'business')
            ->assertJsonPath('data.opening_balance', '250000.00')
            ->assertJsonPath('data.balance', '250000.00');
    }

    public function test_account_names_are_unique_within_a_scope(): void
    {
        $this->postJson('/api/accounts', $this->payload())->assertCreated();

        $this->postJson('/api/accounts', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        // The same name is fine on the other side (personal).
        $this->postJson('/api/accounts', $this->payload(['scope' => 'personal']))->assertCreated();
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->postJson('/api/accounts', $this->payload([
            'scope' => 'other', 'type' => 'piggybank', 'opening_balance' => '12.345',
            'opening_balance_date' => now()->addDay()->toDateString(),
        ]))->assertStatus(422)->assertJsonValidationErrors(['scope', 'type', 'opening_balance', 'opening_balance_date']);
    }

    public function test_list_shows_balances_and_keeps_business_and_personal_totals_apart(): void
    {
        $this->makeAccount($this->user, ['name' => 'Shop cash', 'opening_balance' => '1000.00']);
        $this->makeAccount($this->user, ['name' => 'Wallet', 'scope' => Scope::Personal, 'opening_balance' => '500.00']);

        $this->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.totals.business', '1000.00')
            ->assertJsonPath('meta.totals.personal', '500.00')
            ->assertJsonPath('meta.totals.all', '1500.00');

        $this->getJson('/api/accounts?scope=personal')->assertJsonCount(1, 'data');
    }

    public function test_other_users_accounts_are_invisible(): void
    {
        $other = $this->makeUser();
        $theirs = $this->makeAccount($other, ['name' => 'Secret']);

        $this->getJson('/api/accounts')->assertJsonCount(0, 'data');
        $this->getJson("/api/accounts/{$theirs->id}")->assertNotFound();
        $this->putJson("/api/accounts/{$theirs->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->deleteJson("/api/accounts/{$theirs->id}")->assertNotFound();
    }

    public function test_an_account_can_be_renamed_and_deactivated_but_not_moved_between_scopes(): void
    {
        $account = $this->makeAccount($this->user, ['name' => 'Old name']);

        $this->putJson("/api/accounts/{$account->id}", [
            'name' => 'New name', 'is_active' => false, 'scope' => 'personal',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New name')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.scope', 'business');
    }

    public function test_the_opening_date_cannot_move_after_the_first_transaction(): void
    {
        $account = $this->makeAccount($this->user, ['opening_balance_date' => $this->daysAgo(30)]);
        app(LedgerService::class)->post([
            'type' => TransactionType::Income, 'account_id' => $account->id,
            'amount' => '10.00', 'transaction_date' => $this->daysAgo(10),
        ]);

        $this->putJson("/api/accounts/{$account->id}", ['opening_balance_date' => $this->daysAgo(5)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('opening_balance_date');
    }

    public function test_only_unused_accounts_can_be_deleted(): void
    {
        $used = $this->makeAccount($this->user);
        $unused = $this->makeAccount($this->user);
        app(LedgerService::class)->post([
            'type' => TransactionType::Income, 'account_id' => $used->id,
            'amount' => '10.00', 'transaction_date' => $this->daysAgo(1),
        ]);

        $this->deleteJson("/api/accounts/{$used->id}")->assertStatus(422);
        $this->deleteJson("/api/accounts/{$unused->id}")->assertNoContent();
        $this->assertDatabaseMissing('accounts', ['id' => $unused->id]);
    }

    public function test_the_statement_shows_a_running_balance_newest_first(): void
    {
        $account = $this->makeAccount($this->user, ['opening_balance' => '1000.00']);
        $ledger = app(LedgerService::class);
        $ledger->post(['type' => TransactionType::Income, 'account_id' => $account->id,
            'amount' => '500.00', 'transaction_date' => $this->daysAgo(3), 'description' => 'Sale deposit']);
        $voidMe = $ledger->post(['type' => TransactionType::Income, 'account_id' => $account->id,
            'amount' => '9999.00', 'transaction_date' => $this->daysAgo(2)]);
        $ledger->void($voidMe, 'Typo');
        $ledger->post(['type' => TransactionType::Expense, 'account_id' => $account->id,
            'amount' => '200.00', 'transaction_date' => $this->daysAgo(1), 'description' => 'Transport']);

        $this->getJson("/api/accounts/{$account->id}/ledger")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.amount', '-200.00')
            ->assertJsonPath('data.0.balance_after', '1300.00')
            ->assertJsonPath('data.0.description', 'Transport')
            ->assertJsonPath('data.1.amount', '500.00')
            ->assertJsonPath('data.1.balance_after', '1500.00');
    }

    public function test_reconciliation_endpoint_records_and_adjusts(): void
    {
        $account = $this->makeAccount($this->user, ['opening_balance' => '1000.00']);

        $this->postJson("/api/accounts/{$account->id}/reconciliations", [
            'actual_balance' => '950', 'reconciled_on' => $this->daysAgo(0), 'post_adjustment' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.recorded_balance', '1000.00')
            ->assertJsonPath('data.difference', '-50.00');

        $this->getJson("/api/accounts/{$account->id}")->assertJsonPath('data.balance', '950.00');
        $this->getJson("/api/accounts/{$account->id}/reconciliations")->assertJsonCount(1, 'data');
    }
}
