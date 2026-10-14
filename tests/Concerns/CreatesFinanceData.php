<?php

namespace Tests\Concerns;

use App\Enums\AccountType;
use App\Enums\Scope;
use App\Models\Account;
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

    protected function daysAgo(int $days): string
    {
        return now()->subDays($days)->toDateString();
    }
}
