<?php

namespace Tests\Feature;

use App\Enums\Scope;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesFinanceData;
use Tests\TestCase;

class LedgerServiceTest extends TestCase
{
    use CreatesFinanceData, RefreshDatabase;

    private User $user;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->makeUser();
        $this->actingAs($this->user);
        $this->ledger = app(LedgerService::class);
    }

    private function balance(Account $account): string
    {
        return Account::query()->withBalance()->findOrFail($account->id)->balance;
    }

    private function postTx(TransactionType $type, Account $account, string $amount, array $extra = [])
    {
        return $this->ledger->post(array_merge([
            'type' => $type,
            'account_id' => $account->id,
            'amount' => $amount,
            'transaction_date' => $this->daysAgo(2),
        ], $extra));
    }

    private function assertRejected(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected a ValidationException for '.$field);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_balance_is_opening_balance_plus_posted_entries(): void
    {
        $cash = $this->makeAccount($this->user, ['opening_balance' => '1000.00']);

        $this->postTx(TransactionType::Income, $cash, '500.00');
        $this->postTx(TransactionType::Expense, $cash, '200.00');

        $this->assertSame('1300.00', $this->balance($cash));
    }

    public function test_amounts_stay_exact(): void
    {
        $cash = $this->makeAccount($this->user);

        foreach (range(1, 3) as $ignored) {
            $this->postTx(TransactionType::Income, $cash, '0.10');
        }

        $this->assertSame('0.30', $this->balance($cash));
    }

    public function test_transfer_creates_two_balancing_entries_and_no_income_or_expense(): void
    {
        $cash = $this->makeAccount($this->user, ['opening_balance' => '1000.00']);
        $mpesa = $this->makeAccount($this->user, ['type' => 'mobile_money']);

        $tx = $this->postTx(TransactionType::Transfer, $cash, '400.00', ['to_account_id' => $mpesa->id]);

        $this->assertSame('600.00', $this->balance($cash));
        $this->assertSame('400.00', $this->balance($mpesa));
        $this->assertCount(2, $tx->entries);
        $this->assertSame('0.00', Money::sum($tx->entries->pluck('amount')));
        $this->assertSame(TransactionType::Transfer, $tx->type);
    }

    public function test_transfer_cannot_mix_business_and_personal(): void
    {
        $business = $this->makeAccount($this->user, ['opening_balance' => '1000.00']);
        $personal = $this->makeAccount($this->user, ['scope' => Scope::Personal]);

        $this->assertRejected(
            fn () => $this->postTx(TransactionType::Transfer, $business, '100.00', ['to_account_id' => $personal->id]),
            'to_account_id'
        );
    }

    public function test_owner_withdrawal_moves_money_from_business_to_personal(): void
    {
        $business = $this->makeAccount($this->user, ['opening_balance' => '1000.00']);
        $personal = $this->makeAccount($this->user, ['scope' => Scope::Personal]);

        $tx = $this->postTx(TransactionType::OwnerWithdrawal, $business, '300.00', ['to_account_id' => $personal->id]);

        $this->assertSame('700.00', $this->balance($business));
        $this->assertSame('300.00', $this->balance($personal));
        $this->assertSame(Scope::Business, $tx->scope);
    }

    public function test_owner_withdrawal_in_the_wrong_direction_is_rejected(): void
    {
        $business = $this->makeAccount($this->user);
        $personal = $this->makeAccount($this->user, ['scope' => Scope::Personal, 'opening_balance' => '500.00']);

        $this->assertRejected(
            fn () => $this->postTx(TransactionType::OwnerWithdrawal, $personal, '100.00', ['to_account_id' => $business->id]),
            'to_account_id'
        );
    }

    public function test_inventory_purchase_needs_a_business_account(): void
    {
        $personal = $this->makeAccount($this->user, ['scope' => Scope::Personal, 'opening_balance' => '500.00']);

        $this->assertRejected(
            fn () => $this->postTx(TransactionType::InventoryPurchase, $personal, '100.00'),
            'account_id'
        );
    }

    public function test_adjustment_needs_a_direction(): void
    {
        $cash = $this->makeAccount($this->user);

        $this->assertRejected(fn () => $this->postTx(TransactionType::Adjustment, $cash, '10.00'), 'direction');
    }

    public function test_posting_before_the_opening_date_is_rejected(): void
    {
        $cash = $this->makeAccount($this->user, ['opening_balance_date' => $this->daysAgo(10)]);

        $this->assertRejected(
            fn () => $this->postTx(TransactionType::Income, $cash, '10.00', ['transaction_date' => $this->daysAgo(20)]),
            'transaction_date'
        );
    }

    public function test_future_dates_are_rejected(): void
    {
        $cash = $this->makeAccount($this->user);

        $this->assertRejected(
            fn () => $this->postTx(TransactionType::Income, $cash, '10.00', ['transaction_date' => now()->addDay()->toDateString()]),
            'transaction_date'
        );
    }

    public function test_inactive_accounts_are_rejected(): void
    {
        $cash = $this->makeAccount($this->user, ['is_active' => false]);

        $this->assertRejected(fn () => $this->postTx(TransactionType::Income, $cash, '10.00'), 'account_id');
    }

    public function test_zero_and_negative_amounts_are_rejected(): void
    {
        $cash = $this->makeAccount($this->user);

        $this->assertRejected(fn () => $this->postTx(TransactionType::Income, $cash, '0'), 'amount');
        $this->assertRejected(fn () => $this->postTx(TransactionType::Income, $cash, '-5.00'), 'amount');
    }

    public function test_amounts_with_more_than_two_decimals_are_rejected(): void
    {
        $cash = $this->makeAccount($this->user);

        $this->expectException(\InvalidArgumentException::class);
        $this->postTx(TransactionType::Income, $cash, '10.005');
    }

    public function test_voiding_removes_the_effect_from_the_balance_and_cannot_repeat(): void
    {
        $cash = $this->makeAccount($this->user, ['opening_balance' => '100.00']);
        $tx = $this->postTx(TransactionType::Income, $cash, '500.00');
        $this->assertSame('600.00', $this->balance($cash));

        $voided = $this->ledger->void($tx, 'Entered by mistake');

        $this->assertSame('voided', $voided->status);
        $this->assertSame('100.00', $this->balance($cash));
        $this->assertRejected(fn () => $this->ledger->void($tx, 'Again'), 'transaction');
    }

    public function test_editing_a_transfer_rebuilds_the_ledger_entries(): void
    {
        $cash = $this->makeAccount($this->user, ['opening_balance' => '1000.00']);
        $mpesa = $this->makeAccount($this->user, ['type' => 'mobile_money']);
        $tx = $this->postTx(TransactionType::Transfer, $cash, '400.00', ['to_account_id' => $mpesa->id]);

        $updated = $this->ledger->update($tx, ['amount' => '250.00']);

        $this->assertSame('750.00', $this->balance($cash));
        $this->assertSame('250.00', $this->balance($mpesa));
        $this->assertCount(2, $updated->entries);
        $this->assertSame(2, \App\Models\LedgerEntry::query()->where('transaction_id', $tx->id)->count());
    }

    public function test_a_voided_transaction_cannot_be_edited(): void
    {
        $cash = $this->makeAccount($this->user);
        $tx = $this->postTx(TransactionType::Income, $cash, '10.00');
        $this->ledger->void($tx, 'Mistake');

        $this->assertRejected(fn () => $this->ledger->update($tx, ['amount' => '20.00']), 'transaction');
    }

    public function test_another_users_account_cannot_be_used(): void
    {
        $other = $this->makeUser();
        $theirs = $this->makeAccount($other, ['opening_balance' => '5000.00']);

        $this->assertRejected(fn () => $this->postTx(TransactionType::Income, $theirs, '10.00'), 'account_id');
    }

    public function test_every_change_is_written_to_the_audit_log(): void
    {
        $cash = $this->makeAccount($this->user);
        $tx = $this->postTx(TransactionType::Income, $cash, '10.00');
        $this->ledger->update($tx, ['amount' => '12.00']);
        $this->ledger->void($tx, 'Mistake');

        $events = AuditLog::query()->where('auditable_id', $tx->id)->orderBy('id')->pluck('event')->all();

        $this->assertSame(['created', 'updated', 'voided'], $events);
    }

    public function test_negative_balance_warning_is_reported(): void
    {
        $cash = $this->makeAccount($this->user, ['name' => 'Drawer', 'opening_balance' => '100.00']);
        $this->postTx(TransactionType::Expense, $cash, '150.00');

        $warnings = $this->ledger->negativeBalanceWarnings([$cash->id]);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Drawer', $warnings[0]);
    }
}