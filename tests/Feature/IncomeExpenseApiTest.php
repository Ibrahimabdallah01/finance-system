<?php

namespace Tests\Feature;

use App\Enums\CategoryKind;
use App\Enums\Scope;
use App\Models\Account;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFinanceData;
use Tests\TestCase;

class IncomeExpenseApiTest extends TestCase
{
    use CreatesFinanceData, RefreshDatabase;

    private User $user;

    private Account $wallet;      // personal, 10,000

    private Account $shopCash;    // business, 50,000

    private TransactionCategory $food;        // personal expense

    private TransactionCategory $commission;  // personal income

    private TransactionCategory $ads;         // business expense

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->makeUser();
        $this->actingAs($this->user);

        $this->wallet = $this->makeAccount($this->user, ['name' => 'Wallet', 'scope' => Scope::Personal, 'opening_balance' => '10000.00']);
        $this->shopCash = $this->makeAccount($this->user, ['name' => 'Shop cash', 'opening_balance' => '50000.00']);
        $this->food = $this->makeCategory($this->user, ['name' => 'Food']);
        $this->commission = $this->makeCategory($this->user, ['name' => 'Commissions', 'kind' => CategoryKind::Income]);
        $this->ads = $this->makeCategory($this->user, ['name' => 'Ads', 'scope' => Scope::Business]);
    }

    private function expense(array $overrides = [])
    {
        return $this->postJson('/api/expenses', array_merge([
            'account_id' => $this->wallet->id,
            'category_id' => $this->food->id,
            'amount' => '2500.50',
            'transaction_date' => $this->daysAgo(1),
            'description' => 'Lunch',
        ], $overrides));
    }

    private function balanceOf(Account $account): string
    {
        return $this->getJson("/api/accounts/{$account->id}")->json('data.balance');
    }

    public function test_a_personal_expense_reduces_the_account_and_needs_no_recipient(): void
    {
        $this->expense()
            ->assertCreated()
            ->assertJsonPath('data.type', 'expense')
            ->assertJsonPath('data.scope', 'personal')
            ->assertJsonPath('data.amount', '2500.50')
            ->assertJsonPath('data.category.name', 'Food')
            ->assertJsonPath('data.entries.0.amount', '-2500.50')
            ->assertJsonPath('meta.warnings', []);

        $this->assertSame('7499.50', $this->balanceOf($this->wallet));
    }

    public function test_personal_income_increases_the_account(): void
    {
        $this->postJson('/api/income', [
            'account_id' => $this->wallet->id,
            'category_id' => $this->commission->id,
            'amount' => 4000,
            'transaction_date' => $this->daysAgo(2),
            'description' => 'Brokerage commission',
            'recipient' => 'Customer A',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'income')
            ->assertJsonPath('data.entries.0.amount', '4000.00');

        $this->assertSame('14000.00', $this->balanceOf($this->wallet));
    }

    public function test_the_category_must_match_the_kind_scope_and_be_active(): void
    {
        // An income category on an expense.
        $this->expense(['category_id' => $this->commission->id])
            ->assertStatus(422)->assertJsonValidationErrors('category_id');

        // A business category on a personal account.
        $this->expense(['category_id' => $this->ads->id])
            ->assertStatus(422)->assertJsonValidationErrors('category_id');

        $this->food->update(['is_active' => false]);
        $this->expense()->assertStatus(422)->assertJsonValidationErrors('category_id');

        $this->assertSame('10000.00', $this->balanceOf($this->wallet));
    }

    public function test_category_and_description_are_required(): void
    {
        $this->postJson('/api/expenses', [
            'account_id' => $this->wallet->id, 'amount' => '10', 'transaction_date' => $this->daysAgo(1),
        ])->assertStatus(422)->assertJsonValidationErrors(['category_id', 'description']);
    }

    public function test_another_users_category_cannot_be_used(): void
    {
        $theirs = $this->makeCategory($this->makeUser());

        $this->expense(['category_id' => $theirs->id])
            ->assertStatus(422)->assertJsonValidationErrors('category_id');
    }

    public function test_future_dates_are_rejected(): void
    {
        $this->expense(['transaction_date' => now()->addDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('transaction_date');
    }

    public function test_a_business_expense_needs_a_recipient(): void
    {
        $payload = [
            'account_id' => $this->shopCash->id, 'category_id' => $this->ads->id,
            'description' => 'Facebook ads',
        ];

        $this->expense($payload)->assertStatus(422)->assertJsonValidationErrors('recipient');

        $this->expense(array_merge($payload, ['recipient' => 'Meta']))
            ->assertCreated()
            ->assertJsonPath('data.scope', 'business')
            ->assertJsonPath('data.recipient', 'Meta');
    }

    public function test_going_below_zero_is_allowed_with_a_warning(): void
    {
        $this->expense(['amount' => '25000'])
            ->assertCreated()
            ->assertJsonCount(1, 'meta.warnings');
    }

    public function test_an_expense_can_be_edited(): void
    {
        $other = $this->makeCategory($this->user, ['name' => 'Transport']);
        $id = $this->expense()->json('data.id');

        $this->putJson("/api/expenses/{$id}", ['amount' => '1000', 'category_id' => $other->id, 'description' => 'Bus fare'])
            ->assertOk()
            ->assertJsonPath('data.amount', '1000.00')
            ->assertJsonPath('data.category.name', 'Transport')
            ->assertJsonPath('data.description', 'Bus fare');

        $this->assertSame('9000.00', $this->balanceOf($this->wallet));

        $this->putJson("/api/expenses/{$id}", ['category_id' => $this->commission->id])
            ->assertStatus(422)->assertJsonValidationErrors('category_id');
    }

    public function test_an_expense_whose_category_was_later_deactivated_can_still_be_edited(): void
    {
        $id = $this->expense()->json('data.id');
        $this->food->update(['is_active' => false]);

        $this->putJson("/api/expenses/{$id}", ['amount' => '700'])
            ->assertOk()
            ->assertJsonPath('data.amount', '700.00')
            ->assertJsonPath('data.category.name', 'Food');
    }

    public function test_the_payment_account_of_an_expense_can_be_changed(): void
    {
        $bank = $this->makeAccount($this->user, ['name' => 'Bank', 'scope' => Scope::Personal, 'opening_balance' => '5000.00']);
        $id = $this->expense()->json('data.id');

        $this->putJson("/api/expenses/{$id}", ['account_id' => $bank->id])->assertOk();

        $this->assertSame('10000.00', $this->balanceOf($this->wallet));
        $this->assertSame('2499.50', $this->balanceOf($bank));
    }

    public function test_a_business_expense_recipient_cannot_be_cleared(): void
    {
        $id = $this->expense([
            'account_id' => $this->shopCash->id, 'category_id' => $this->ads->id, 'recipient' => 'Meta',
        ])->json('data.id');

        $this->putJson("/api/expenses/{$id}", ['recipient' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('recipient');
    }

    public function test_income_and_expense_endpoints_do_not_edit_each_others_records(): void
    {
        $id = $this->expense()->json('data.id');

        $this->putJson("/api/income/{$id}", ['amount' => '10'])->assertStatus(422)->assertJsonValidationErrors('transaction');
    }

    public function test_history_lists_created_updated_and_voided(): void
    {
        $id = $this->expense()->json('data.id');
        $this->putJson("/api/expenses/{$id}", ['amount' => '1000'])->assertOk();
        $this->postJson("/api/transactions/{$id}/void", ['reason' => 'Duplicate entry'])->assertOk();

        $events = collect($this->getJson("/api/transactions/{$id}/history")->assertOk()->json('data'))->pluck('event')->all();

        $this->assertSame(['created', 'updated', 'voided'], $events);
        $this->assertSame('10000.00', $this->balanceOf($this->wallet));
    }

    public function test_the_summary_counts_posted_rows_per_type_and_ignores_voided_and_transfers(): void
    {
        $this->expense(['amount' => '1000'])->assertCreated();
        $this->expense(['amount' => '2000'])->assertCreated();
        $voidId = $this->expense(['amount' => '9999'])->json('data.id');
        $this->postJson("/api/transactions/{$voidId}/void", ['reason' => 'Mistake'])->assertOk();

        $this->postJson('/api/income', [
            'account_id' => $this->wallet->id, 'category_id' => $this->commission->id,
            'amount' => '5000', 'transaction_date' => $this->daysAgo(1), 'description' => 'Commission',
        ])->assertCreated();

        $bank = $this->makeAccount($this->user, ['name' => 'Bank', 'scope' => Scope::Personal]);
        $this->postJson('/api/transfers', [
            'from_account_id' => $this->wallet->id, 'to_account_id' => $bank->id,
            'amount' => '1500', 'transaction_date' => $this->daysAgo(1),
        ])->assertCreated();

        $this->getJson('/api/transactions?scope=personal')
            ->assertOk()
            ->assertJsonPath('meta.summary.by_type.expense.total', '3000.00')
            ->assertJsonPath('meta.summary.by_type.expense.count', 2)
            ->assertJsonPath('meta.summary.by_type.income.total', '5000.00')
            ->assertJsonPath('meta.summary.by_type.transfer.total', '1500.00');

        $this->getJson('/api/transactions?type=expense')
            ->assertJsonPath('meta.summary.by_type.expense.total', '3000.00')
            ->assertJsonMissingPath('meta.summary.by_type.income');
    }

    public function test_an_owner_withdrawal_is_not_an_expense(): void
    {
        $this->postJson('/api/transfers', [
            'from_account_id' => $this->shopCash->id, 'to_account_id' => $this->wallet->id,
            'amount' => '3000', 'transaction_date' => $this->daysAgo(1),
        ])->assertCreated()->assertJsonPath('data.type', 'owner_withdrawal');

        $this->getJson('/api/transactions?type=expense')->assertJsonCount(0, 'data')->assertJsonPath('meta.summary.by_type', []);
    }

    public function test_filtering_by_a_parent_category_includes_its_subcategories(): void
    {
        $groceries = $this->makeCategory($this->user, ['name' => 'Groceries', 'parent_id' => $this->food->id]);
        $transport = $this->makeCategory($this->user, ['name' => 'Transport']);

        $this->expense(['category_id' => $groceries->id, 'description' => 'Rice'])->assertCreated();
        $this->expense(['category_id' => $this->food->id, 'description' => 'Lunch'])->assertCreated();
        $this->expense(['category_id' => $transport->id, 'description' => 'Bus'])->assertCreated();

        $this->getJson("/api/transactions?category_id={$this->food->id}")->assertJsonCount(2, 'data');
        $this->getJson("/api/transactions?category_id={$groceries->id}")->assertJsonCount(1, 'data');
        $this->getJson("/api/transactions?category_id={$transport->id}&search=bus")->assertJsonCount(1, 'data');
    }
}
