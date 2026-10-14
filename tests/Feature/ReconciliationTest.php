<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesFinanceData;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use CreatesFinanceData, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->makeUser();
        $this->actingAs($this->user);
    }

    private function balance(Account $account): string
    {
        return Account::query()->withBalance()->findOrFail($account->id)->balance;
    }

    private function income(Account $account, string $amount, int $daysAgo): void
    {
        app(LedgerService::class)->post([
            'type' => TransactionType::Income,
            'account_id' => $account->id,
            'amount' => $amount,
            'transaction_date' => $this->daysAgo($daysAgo),
        ]);
    }

    public function test_a_matching_count_records_no_difference_and_no_adjustment(): void
    {
        $cash = $this->makeAccount($this->user, ['opening_balance' => '1000.00']);

        $rec = app(ReconciliationService::class)->reconcile($cash, '1000.00', $this->daysAgo(0), true);

        $this->assertSame('0.00', $rec->difference);
        $this->assertNull($rec->adjustment_transaction_id);
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_a_shortfall_can_be_posted_as_an_adjustment(): void
    {
        $cash = $this->makeAccount($this->user, ['opening_balance' => '1000.00']);
        $this->income($cash, '500.00', 3);

        $rec = app(ReconciliationService::class)->reconcile($cash, '1400.00', $this->daysAgo(0), true, 'Counted at close');

        $this->assertSame('1500.00', $rec->recorded_balance);
        $this->assertSame('1400.00', $rec->actual_balance);
        $this->assertSame('-100.00', $rec->difference);
        $this->assertNotNull($rec->adjustment_transaction_id);
        $this->assertSame(TransactionType::Adjustment, $rec->adjustment->type);
        $this->assertSame('1400.00', $this->balance($cash));
    }

    public function test_a_surplus_adjustment_adds_money(): void
    {
        $cash = $this->makeAccount($this->user, ['opening_balance' => '1000.00']);

        app(ReconciliationService::class)->reconcile($cash, '1050.00', $this->daysAgo(0), true);

        $this->assertSame('1050.00', $this->balance($cash));
    }

    public function test_without_the_adjustment_option_the_balance_is_unchanged(): void
    {
        $cash = $this->makeAccount($this->user, ['opening_balance' => '1000.00']);

        $rec = app(ReconciliationService::class)->reconcile($cash, '900.00', $this->daysAgo(0), false);

        $this->assertSame('-100.00', $rec->difference);
        $this->assertNull($rec->adjustment_transaction_id);
        $this->assertSame('1000.00', $this->balance($cash));
    }

    public function test_a_past_date_uses_the_balance_at_that_date(): void
    {
        $cash = $this->makeAccount($this->user, ['opening_balance' => '1000.00']);
        $this->income($cash, '500.00', 1);

        $rec = app(ReconciliationService::class)->reconcile($cash, '1000.00', $this->daysAgo(3), false);

        $this->assertSame('1000.00', $rec->recorded_balance);
        $this->assertSame('0.00', $rec->difference);
    }

    public function test_reconciling_before_the_opening_date_is_rejected(): void
    {
        $cash = $this->makeAccount($this->user, ['opening_balance_date' => $this->daysAgo(5)]);

        $this->expectException(ValidationException::class);
        app(ReconciliationService::class)->reconcile($cash, '0.00', $this->daysAgo(10), false);
    }
}
