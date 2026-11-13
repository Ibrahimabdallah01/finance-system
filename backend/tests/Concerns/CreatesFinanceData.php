<?php

namespace Tests\Concerns;

use App\Enums\AccountType;
use App\Enums\CategoryKind;
use App\Enums\Scope;
use App\Models\Account;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Support\Str;

trait CreatesFinanceData
{
    protected function makeUser(): User
    {
        $user = User::factory()->create();
        $user->business()->create(['name' => 'Test Business']);

        return $user;
    }

    /** Creates an account directly for the given user (works for any user, logged in or not). */
    protected function makeAccount(User $user, array $attributes = []): Account
    {
        $account = new Account(array_merge([
            'scope' => Scope::Business,
            'name' => 'Account '.Str::random(8),
            'type' => AccountType::Cash,
            'opening_balance' => '0.00',
            'opening_balance_date' => now()->subYear()->toDateString(),
            'is_active' => true,
        ], $attributes));

        $account->user_id = $user->id;
        $account->save();

        return $account;
    }

    protected function makeCategory(User $user, array $attributes = []): TransactionCategory
    {
        $category = new TransactionCategory(array_merge([
            'scope' => Scope::Personal,
            'kind' => CategoryKind::Expense,
            'name' => 'Category '.Str::random(8),
            'is_system' => false,
            'is_active' => true,
            'sort_order' => 0,
        ], $attributes));

        $category->user_id = $user->id;
        $category->save();

        return $category;
    }

    protected function daysAgo(int $days): string
    {
        return now()->subDays($days)->toDateString();
    }
}
