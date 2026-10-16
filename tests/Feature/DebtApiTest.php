<?php

namespace Tests\Feature;

use App\Enums\CategoryKind;
use App\Enums\Scope;
use App\Models\Debt;
use App\Models\TransactionCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpShop;
use Tests\TestCase;

class DebtApiTest extends TestCase
{
    use RefreshDatabase, SetsUpShop;

    private TransactionCategory $interestPaid;     // personal expense

    private TransactionCategory $interestReceived; // personal income

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();

        $this->interestPaid = $this->makeCategory($this->user, ['name' => 'Loan interest']);
        $this->interestReceived = $this->makeCategory($this->user, ['name' => 'Interest received', 'kind' => CategoryKind::Income]);
    }

    private function loan(string $direction, string $amount = '300000', array $overrides = [])
    {
        return $this->postJson('/api/debts', array_merge([
            'scope' => 'personal', 'direction' => $direction, 'source' => 'loan',
            'party_name' => $direction === 'payable' ? 'Mama' : 'Juma',
            'original_amount' => $amount, 'issued_on' => $this->daysAgo(20),
            'due_date' => now()->addDays(30)->toDateString(), 'account_id' => $this->wallet->id,
        ], $overrides));
    }

    private function repay(int $debtId, string $amount, array $extra = [])
    {
        return $this->postJson("/api/debts/{$debtId}/repayments", array_merge([
            'account_id' => $this->wallet->id, 'amount' => $amount, 'transaction_date' => $this->daysAgo(10),
        ], $extra));
    }

    public function test_borrowing_money_is_a_liability_and_never_income(): void
    {
        $this->loan('payable')
            ->assertCreated()
            ->assertJsonPath('data.direction', 'payable')
            ->assertJsonPath('data.figures.status', 'open')
            ->assertJsonPath('data.figures.outstanding_balance', '300000.00')
            ->assertJsonPath('data.figures.overdue', false);

        $this->assertSame('300000.00', $this->balanceOf($this->wallet));
        $this->getJson('/api/transactions?type=income')->assertJsonPath('meta.summary.by_type', []);
        $this->getJson('/api/transactions?type=loan_received')->assertJsonPath('meta.summary.by_type.loan_received.total', '300000.00');
    }

    public function test_repaying_principal_reduces_the_debt_and_is_not_an_expense(): void
    {
        $id = $this->loan('payable')->json('data.id');

        $this->repay($id, '100000')
            ->assertCreated()
            ->assertJsonPath('data.figures.repaid', '100000.00')
            ->assertJsonPath('data.figures.outstanding_balance', '200000.00')
            ->assertJsonPath('data.figures.status', 'open')
            ->assertJsonCount(1, 'data.repayments');

        $this->assertSame('200000.00', $this->balanceOf($this->wallet));
        $this->getJson('/api/transactions?type=expense')->assertJsonPath('meta.summary.by_type', []);
        $this->getJson('/api/transactions?type=debt_repayment_out')->assertJsonPath('meta.summary.by_type.debt_repayment_out.total', '100000.00');

        $this->repay($id, '200000')->assertCreated()->assertJsonPath('data.figures.status', 'settled');
        $this->repay($id, '1')->assertStatus(422)->assertJsonValidationErrors('debt');
    }

    public function test_repayments_cannot_exceed_the_balance_or_predate_the_debt(): void
    {
        $id = $this->loan('payable')->json('data.id');

        $this->repay($id, '300001')->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->repay($id, '1000', ['transaction_date' => $this->daysAgo(30)])->assertStatus(422)->assertJsonValidationErrors('transaction_date');

        $this->assertSame('300000.00', $this->balanceOf($this->wallet));
    }

    public function test_interest_is_recorded_separately_as_an_expense(): void
    {
        $id = $this->loan('payable')->json('data.id');

        $this->repay($id, '100000', ['interest_amount' => '5000', 'interest_category_id' => $this->interestPaid->id])
            ->assertCreated()
            ->assertJsonPath('data.figures.outstanding_balance', '200000.00')
            ->assertJsonPath('data.figures.interest_total', '5000.00')
            ->assertJsonCount(1, 'data.interest');

        $this->assertSame('195000.00', $this->balanceOf($this->wallet));
        $this->getJson('/api/transactions?type=expense')
            ->assertJsonPath('meta.summary.by_type.expense.total', '5000.00')
            ->assertJsonPath('meta.summary.by_type.expense.count', 1);

        // Interest without a category records nothing at all (the principal is rolled back too).
        $this->repay($id, '50000', ['interest_amount' => '1000'])
            ->assertStatus(422)->assertJsonValidationErrors('interest_category_id');
        $this->assertSame('195000.00', $this->balanceOf($this->wallet));
    }

    public function test_lending_money_creates_a_receivable_and_interest_received_is_income(): void
    {
        $this->wallet->update(['opening_balance' => '500000.00']);

        $id = $this->loan('receivable', '100000')
            ->assertCreated()
            ->assertJsonPath('data.direction', 'receivable')
            ->assertJsonPath('data.figures.outstanding_balance', '100000.00')
            ->json('data.id');

        $this->assertSame('400000.00', $this->balanceOf($this->wallet));
        $this->getJson('/api/transactions?type=loan_given')->assertJsonPath('meta.summary.by_type.loan_given.total', '100000.00');
        $this->getJson('/api/transactions?type=expense')->assertJsonPath('meta.summary.by_type', []);

        $this->repay($id, '40000', ['interest_amount' => '2000', 'interest_category_id' => $this->interestReceived->id])
            ->assertCreated()
            ->assertJsonPath('data.figures.outstanding_balance', '60000.00');

        $this->assertSame('442000.00', $this->balanceOf($this->wallet));
        $this->getJson('/api/transactions?type=income')->assertJsonPath('meta.summary.by_type.income.total', '2000.00');
        $this->getJson('/api/transactions?type=debt_repayment_in')->assertJsonPath('meta.summary.by_type.debt_repayment_in.total', '40000.00');
    }

    public function test_supplier_credit_from_a_stock_purchase_is_repaid_from_a_business_account(): void
    {
        $debtId = $this->postJson('/api/inventory/purchases', [
            'product_id' => $this->phoneProduct->id, 'supplier_id' => $this->supplier->id,
            'purchase_date' => $this->daysAgo(10), 'account_id' => $this->shop->id, 'on_credit' => true,
            'units' => [['imei' => $this->imei(1), 'purchase_price' => '600000', 'additional_costs' => '5000']],
        ])->assertCreated()->json('data.debt_id');

        $this->getJson("/api/debts/{$debtId}")
            ->assertJsonPath('data.direction', 'payable')
            ->assertJsonPath('data.source', 'supplier_credit')
            ->assertJsonPath('data.scope', 'business')
            ->assertJsonPath('data.supplier.name', 'Dubai Traders')
            ->assertJsonPath('data.figures.outstanding_balance', '600000.00');

        // The debt belongs to the business: a personal account cannot repay it.
        $this->repay($debtId, '200000')->assertStatus(422)->assertJsonValidationErrors('account_id');

        $this->repay($debtId, '200000', ['account_id' => $this->shop->id])
            ->assertCreated()
            ->assertJsonPath('data.figures.outstanding_balance', '400000.00');

        $this->assertSame('795000.00', $this->balanceOf($this->shop)); // 1,000,000 - 5,000 - 200,000
        $this->getJson('/api/transactions?type=expense')->assertJsonPath('meta.summary.by_type', []);
    }

    public function test_a_repayment_schedule_is_applied_in_due_date_order(): void
    {
        $id = $this->loan('payable')->json('data.id');

        $this->putJson("/api/debts/{$id}/schedule", ['installments' => [
            ['due_date' => $this->daysAgo(5), 'amount_due' => '100000', 'note' => 'First'],
            ['due_date' => now()->addDays(25)->toDateString(), 'amount_due' => '100000'],
            ['due_date' => now()->addDays(55)->toDateString(), 'amount_due' => '100000'],
        ]])
            ->assertOk()
            ->assertJsonCount(3, 'data.figures.schedule')
            ->assertJsonPath('data.figures.schedule.0.status', 'overdue')
            ->assertJsonPath('data.figures.schedule.1.status', 'upcoming');

        $this->repay($id, '150000')
            ->assertCreated()
            ->assertJsonPath('data.figures.schedule.0.status', 'paid')
            ->assertJsonPath('data.figures.schedule.1.status', 'partial')
            ->assertJsonPath('data.figures.schedule.1.paid', '50000.00')
            ->assertJsonPath('data.figures.schedule.1.remaining', '50000.00')
            ->assertJsonPath('data.figures.schedule.2.status', 'upcoming');
    }

    public function test_a_schedule_cannot_add_up_to_more_than_the_debt_or_start_before_it(): void
    {
        $id = $this->loan('payable')->json('data.id');
        $future = now()->addDays(10)->toDateString();

        $this->putJson("/api/debts/{$id}/schedule", ['installments' => [
            ['due_date' => $future, 'amount_due' => '200000'],
            ['due_date' => $future, 'amount_due' => '200000'],
        ]])->assertStatus(422)->assertJsonValidationErrors('installments');

        $this->putJson("/api/debts/{$id}/schedule", ['installments' => [
            ['due_date' => $this->daysAgo(40), 'amount_due' => '1000'],
        ]])->assertStatus(422)->assertJsonValidationErrors('installments.0.due_date');
    }

    public function test_an_unpaid_debt_past_its_due_date_is_overdue(): void
    {
        $this->loan('payable', '300000', ['due_date' => $this->daysAgo(1)])
            ->assertCreated()
            ->assertJsonPath('data.figures.overdue', true)
            ->assertJsonPath('data.figures.days_overdue', 1);
    }

    public function test_a_written_off_debt_leaves_the_totals_and_cannot_be_repaid(): void
    {
        $id = $this->loan('payable')->json('data.id');

        $this->postJson("/api/debts/{$id}/write-off", ['reason' => 'Mama forgave it'])
            ->assertOk()->assertJsonPath('data.figures.status', 'written_off');

        $this->repay($id, '1000')->assertStatus(422)->assertJsonValidationErrors('debt');
        $this->postJson("/api/debts/{$id}/write-off", ['reason' => 'Again'])->assertStatus(422);
        $this->getJson('/api/debts/summary')->assertJsonPath('data.i_owe.total', '0.00');
        $this->getJson('/api/debts?status=written_off')->assertJsonCount(1, 'data');
        $this->getJson('/api/debts?status=open')->assertJsonCount(0, 'data');
    }

    public function test_a_loan_can_be_cancelled_by_voiding_it_only_while_it_has_no_repayments(): void
    {
        $first = $this->loan('payable')->json('data');
        $this->postJson('/api/transactions/'.$first['figures']['origin_transaction_id'].'/void', ['reason' => 'Entered by mistake'])->assertOk();

        $this->getJson('/api/debts/'.$first['id'])->assertJsonPath('data.figures.status', 'cancelled');
        $this->assertSame('0.00', $this->balanceOf($this->wallet));
        $this->repay($first['id'], '1000')->assertStatus(422)->assertJsonValidationErrors('debt');

        $second = $this->loan('payable', '200000', ['party_name' => 'Baba'])->json('data');
        $this->repay($second['id'], '50000')->assertCreated();

        $this->postJson('/api/transactions/'.$second['figures']['origin_transaction_id'].'/void', ['reason' => 'Mistake'])
            ->assertStatus(422)->assertJsonValidationErrors('transaction');
    }

    public function test_debt_input_is_validated(): void
    {
        $this->loan('payable', '300000', ['account_id' => null])->assertStatus(422)->assertJsonValidationErrors('account_id');
        $this->loan('payable', '300000', ['source' => 'other'])->assertStatus(422)->assertJsonValidationErrors('account_id');
        $this->loan('payable', '300000', ['account_id' => $this->shop->id])->assertStatus(422)->assertJsonValidationErrors('account_id');
        $this->loan('payable', '0')->assertStatus(422)->assertJsonValidationErrors('original_amount');
        $this->loan('payable', '300000', ['due_date' => $this->daysAgo(30)])->assertStatus(422)->assertJsonValidationErrors('due_date');
        $this->loan('payable', '300000', ['issued_on' => now()->addDay()->toDateString(), 'due_date' => null])->assertStatus(422)->assertJsonValidationErrors('issued_on');

        $theirs = $this->makeSupplier($this->makeUser(), 'Not mine');
        $this->loan('payable', '300000', ['supplier_id' => $theirs->id])->assertStatus(422)->assertJsonValidationErrors('supplier_id');

        $this->assertSame(0, Debt::query()->count());
        $this->assertSame('0.00', $this->balanceOf($this->wallet));
    }

    public function test_a_debt_without_cash_can_be_recorded_for_informal_amounts(): void
    {
        $this->loan('receivable', '50000', ['source' => 'other', 'account_id' => null, 'party_name' => 'Neighbour'])
            ->assertCreated()
            ->assertJsonPath('data.figures.outstanding_balance', '50000.00')
            ->assertJsonPath('data.figures.origin_transaction_id', null);

        $this->assertSame('0.00', $this->balanceOf($this->wallet));
    }

    public function test_the_summary_combines_debts_and_customer_balances_without_double_counting(): void
    {
        $this->loan('payable', '300000', ['due_date' => now()->addDays(10)->toDateString()])->assertCreated();
        $this->loan('receivable', '100000', ['due_date' => now()->addDays(5)->toDateString()])->assertCreated();

        // A customer owes 550,000 on a sale that was due yesterday.
        $this->sellPhone($this->stockPhone(1), '750000', [$this->pay('200000')], ['due_date' => $this->daysAgo(1)])->assertCreated();

        $this->getJson('/api/debts/summary?days=30')
            ->assertOk()
            ->assertJsonPath('data.owed_to_me.debts', '100000.00')
            ->assertJsonPath('data.owed_to_me.customer_sales', '550000.00')
            ->assertJsonPath('data.owed_to_me.total', '650000.00')
            ->assertJsonPath('data.i_owe.total', '300000.00')
            ->assertJsonPath('data.i_owe.loans', '300000.00')
            ->assertJsonPath('data.overdue.receivable.count', 1)
            ->assertJsonPath('data.overdue.receivable.amount', '550000.00')
            ->assertJsonPath('data.overdue.payable.count', 0)
            ->assertJsonCount(3, 'data.upcoming')
            ->assertJsonPath('data.upcoming.0.kind', 'sale')
            ->assertJsonPath('data.upcoming.0.overdue', true)
            ->assertJsonPath('data.upcoming.1.direction', 'receivable')
            ->assertJsonPath('data.upcoming.2.direction', 'payable');
    }

    public function test_customer_payments_reduce_what_is_owed_to_me(): void
    {
        $this->loan('receivable', '100000')->assertCreated();
        $saleId = $this->sellPhone($this->stockPhone(1), '750000', [$this->pay('200000')])->json('data.id');

        $this->getJson('/api/receivables')
            ->assertOk()
            ->assertJsonCount(1, 'data.customers')
            ->assertJsonPath('data.customers.0.customer.name', 'Asha')
            ->assertJsonPath('data.customers.0.outstanding_total', '550000.00')
            ->assertJsonPath('data.customers.0.sales.0.sale_number', 'S-00001')
            ->assertJsonCount(1, 'data.debts')
            ->assertJsonPath('data.totals.all', '650000.00');

        $this->postJson("/api/sales/{$saleId}/payments", array_merge($this->pay('550000'), ['transaction_date' => $this->daysAgo(2)]))->assertCreated();

        $this->getJson('/api/receivables')
            ->assertJsonCount(0, 'data.customers')
            ->assertJsonPath('data.totals.customers', '0.00')
            ->assertJsonPath('data.totals.all', '100000.00');
    }

    public function test_debts_can_be_listed_and_filtered(): void
    {
        $this->loan('payable')->assertCreated();
        $this->loan('receivable', '100000')->assertCreated();

        $this->getJson('/api/debts')->assertJsonCount(2, 'data');
        $this->getJson('/api/debts?direction=payable')->assertJsonCount(1, 'data')->assertJsonPath('data.0.party_name', 'Mama');
        $this->getJson('/api/debts?search=juma')->assertJsonCount(1, 'data');
        $this->getJson('/api/debts?scope=business')->assertJsonCount(0, 'data');
        $this->getJson('/api/debts?status=settled')->assertJsonCount(0, 'data');
    }

    public function test_other_users_debts_are_out_of_reach_and_a_login_is_required(): void
    {
        $other = $this->makeUser();
        $theirs = new Debt([
            'scope' => 'personal', 'direction' => 'payable', 'source' => 'other', 'party_name' => 'Secret',
            'original_amount' => '1000', 'issued_on' => $this->daysAgo(5), 'status' => 'open',
        ]);
        $theirs->user_id = $other->id;
        $theirs->save();

        $this->getJson("/api/debts/{$theirs->id}")->assertNotFound();
        $this->repay($theirs->id, '100')->assertNotFound();
        $this->postJson("/api/debts/{$theirs->id}/write-off", ['reason' => 'Nope'])->assertNotFound();
        $this->getJson('/api/debts')->assertJsonCount(0, 'data');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/debts/summary')->assertUnauthorized();
    }
}
