<?php

namespace Tests\Feature;

use App\Enums\CategoryKind;
use App\Enums\Scope;
use App\Enums\TransactionType;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Services\DefaultCategories;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFinanceData;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    use CreatesFinanceData, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->makeUser();
        $this->actingAs($this->user);
    }

    private function countCategories(string $scope, string $kind): int
    {
        return TransactionCategory::query()->where('scope', $scope)->where('kind', $kind)->count();
    }

    public function test_default_categories_are_added_once(): void
    {
        $defaults = app(DefaultCategories::class);

        $this->assertSame(33, $defaults->seedFor($this->user));
        $this->assertSame(0, $defaults->seedFor($this->user));

        $this->assertSame(16, $this->countCategories('personal', 'expense'));
        $this->assertSame(5, $this->countCategories('personal', 'income'));
        $this->assertSame(11, $this->countCategories('business', 'expense'));
        $this->assertSame(1, $this->countCategories('business', 'income'));

        $this->assertDatabaseHas('transaction_categories', ['scope' => 'personal', 'kind' => 'income', 'name' => 'Phone brokerage commissions']);
        $this->assertDatabaseHas('transaction_categories', ['scope' => 'business', 'kind' => 'expense', 'name' => 'Packaging']);
        // Stock is an asset until sold, so it is not an expense category.
        $this->assertDatabaseMissing('transaction_categories', ['name' => 'Purchasing phone stock']);
    }

    public function test_the_list_is_a_tree_of_categories_and_subcategories(): void
    {
        $food = $this->makeCategory($this->user, ['name' => 'Food']);
        $this->makeCategory($this->user, ['name' => 'Groceries', 'parent_id' => $food->id]);
        $this->makeCategory($this->user, ['name' => 'Salary', 'kind' => CategoryKind::Income]);

        $this->getJson('/api/categories?scope=personal&kind=expense')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Food')
            ->assertJsonPath('data.0.children.0.name', 'Groceries');
    }

    public function test_a_category_and_a_subcategory_can_be_created_but_not_a_third_level(): void
    {
        $parent = $this->postJson('/api/categories', ['scope' => 'personal', 'kind' => 'expense', 'name' => 'Transport'])
            ->assertCreated()
            ->assertJsonPath('data.is_system', false)
            ->json('data.id');

        $child = $this->postJson('/api/categories', [
            'scope' => 'personal', 'kind' => 'expense', 'name' => 'Bajaj', 'parent_id' => $parent,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/categories', [
            'scope' => 'personal', 'kind' => 'expense', 'name' => 'Too deep', 'parent_id' => $child,
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_a_subcategory_must_match_its_parents_scope_and_kind(): void
    {
        $parent = $this->makeCategory($this->user);

        $this->postJson('/api/categories', [
            'scope' => 'business', 'kind' => 'expense', 'name' => 'Mismatch', 'parent_id' => $parent->id,
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');

        $this->postJson('/api/categories', [
            'scope' => 'personal', 'kind' => 'income', 'name' => 'Mismatch', 'parent_id' => $parent->id,
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_sibling_names_must_be_unique(): void
    {
        $payload = ['scope' => 'personal', 'kind' => 'expense', 'name' => 'Snacks'];

        $this->postJson('/api/categories', $payload)->assertCreated();
        $this->postJson('/api/categories', $payload)->assertStatus(422)->assertJsonValidationErrors('name');

        // The same name is fine in another scope.
        $this->postJson('/api/categories', array_merge($payload, ['scope' => 'business']))->assertCreated();
    }

    public function test_a_category_can_be_renamed_and_deactivating_it_deactivates_its_children(): void
    {
        $parent = $this->makeCategory($this->user, ['name' => 'Old']);
        $child = $this->makeCategory($this->user, ['parent_id' => $parent->id]);

        $this->putJson("/api/categories/{$parent->id}", ['name' => 'New', 'is_active' => false, 'scope' => 'business'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.scope', 'personal');

        $this->assertFalse($child->fresh()->is_active);
    }

    public function test_only_unused_categories_can_be_deleted(): void
    {
        $unused = $this->makeCategory($this->user);
        $withChild = $this->makeCategory($this->user);
        $this->makeCategory($this->user, ['parent_id' => $withChild->id]);
        $used = $this->makeCategory($this->user);
        $account = $this->makeAccount($this->user, ['scope' => Scope::Personal, 'opening_balance' => '1000.00']);

        app(LedgerService::class)->post([
            'type' => TransactionType::Expense, 'account_id' => $account->id, 'category_id' => $used->id,
            'amount' => '10.00', 'transaction_date' => $this->daysAgo(1),
        ]);

        $this->deleteJson("/api/categories/{$withChild->id}")->assertStatus(422);
        $this->deleteJson("/api/categories/{$used->id}")->assertStatus(422);
        $this->deleteJson("/api/categories/{$unused->id}")->assertNoContent();
        $this->assertDatabaseMissing('transaction_categories', ['id' => $unused->id]);
    }

    public function test_other_users_categories_are_invisible(): void
    {
        $theirs = $this->makeCategory($this->makeUser(), ['name' => 'Secret']);

        $this->getJson('/api/categories')->assertJsonCount(0, 'data');
        $this->putJson("/api/categories/{$theirs->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->deleteJson("/api/categories/{$theirs->id}")->assertNotFound();
    }
}