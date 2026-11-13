<?php

namespace Tests\Feature;

use App\Enums\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFinanceData;
use Tests\TestCase;

class TransferApiTest extends TestCase
{
    use CreatesFinanceData, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->makeUser();
        $this->actingAs($this->user);
    }

    private function balanceOf(int $accountId): string
    {
        return $this->getJson("/api/accounts/{$accountId}")->json('data.balance');
    }

    private function transfer(int $from, int $to, string $amount, array $extra = [])
    {
        return $this->postJson('/api/transfers', array_merge([
            'from_account_id' => $from,
            'to_account_id' => $to,
            'amount' => $amount,
            'transaction_date' => $this->daysAgo(1),
        ], $extra));
    }

    public function test_a_transfer_moves_money_between_two_accounts(): void
    {
        $cash = $this->makeAccount($this->user, ['opening_balance' => '1000.00']);
        $mpesa = $this->makeAccount($this->user, ['type' => 'mobile_money']);

        $this->transfer($cash->id, $mpesa->id, '400.50', ['description' => 'Float top-up'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'transfer')
            ->assertJsonPath('data.scope', 'business')
            ->assertJsonPath('data.amount', '400.50')
            ->assertJsonCount(2, 'data.entries')
            ->assertJsonPath('meta.warnings', []);

        $this->assertSame('599.50', $this->balanceOf($cash->id));
        $this->assertSame('400.50', $this->balanceOf($mpesa->id));
    }

    public function test_business_to_personal_becomes_an_owner_withdrawal(): void
    {
        $business = $this->makeAccount($this->user, ['opening_balance' => '1000.00']);
        $personal = $this->makeAccount($this->user, ['scope' => Scope::Personal]);

        $this->transfer($business->id, $personal->id, '300')
            ->assertCreated()
            ->assertJsonPath('data.type', 'owner_withdrawal');
    }

    public function test_personal_to_business_becomes_an_owner_contribution(): void
    {
        $business = $this->makeAccount($this->user);
        $personal = $this->makeAccount($this->user, ['scope' => Scope::Personal, 'opening_balance' => '800.00']);

        $this->transfer($personal->id, $business->id, '300')
            ->assertCreated()
            ->assertJsonPath('data.type', 'owner_contribution');
    }

    public function test_going_below_zero_is_allowed_but_warned_about(): void
    {
        $cash = $this->makeAccount($this->user, ['name' => 'Drawer', 'opening_balance' => '100.00']);
        $mpesa = $this->makeAccount($this->user);

        $this->transfer($cash->id, $mpesa->id, '500')
            ->assertCreated()
            ->assertJsonCount(1, 'meta.warnings');
    }

    public function test_validation_errors_use_the_transfer_field_names(): void
    {
        $cash = $this->makeAccount($this->user);

        $this->transfer($cash->id, $cash->id, '10')->assertStatus(422)->assertJsonValidationErrors('to_account_id');
        $this->transfer($cash->id, $cash->id + 999, '12.345')->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->transfer($cash->id, $cash->id + 999, '10', ['transaction_date' => now()->addDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('transaction_date');
        $this->transfer($cash->id + 999, $cash->id, '10')->assertStatus(422)->assertJsonValidationErrors('from_account_id');
    }

    public function test_another_users_account_cannot_be_the_destination(): void
    {
        $cash = $this->makeAccount($this->user, ['opening_balance' => '1000.00']);
        $theirs = $this->makeAccount($this->makeUser());

        $this->transfer($cash->id, $theirs->id, '10')->assertStatus(422)->assertJsonValidationErrors('to_account_id');
        $this->assertSame('1000.00', $this->balanceOf($cash->id));
    }

    public function test_a_transfer_can_be_edited_and_voided(): void
    {
        $cash = $this->makeAccount($this->user, ['opening_balance' => '1000.00']);
        $mpesa = $this->makeAccount($this->user);
        $id = $this->transfer($cash->id, $mpesa->id, '400')->json('data.id');

        $this->putJson("/api/transfers/{$id}", ['amount' => '250'])->assertOk()->assertJsonPath('data.amount', '250.00');
        $this->assertSame('750.00', $this->balanceOf($cash->id));
        $this->assertSame('250.00', $this->balanceOf($mpesa->id));

        $this->postJson("/api/transactions/{$id}/void", ['reason' => 'Wrong account'])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided');
        $this->assertSame('1000.00', $this->balanceOf($cash->id));
        $this->assertSame('0.00', $this->balanceOf($mpesa->id));

        $this->putJson("/api/transfers/{$id}", ['amount' => '10'])->assertStatus(422);
        $this->postJson("/api/transactions/{$id}/void", ['reason' => 'Again'])->assertStatus(422);
    }

    public function test_the_transaction_list_can_be_filtered(): void
    {
        $cash = $this->makeAccount($this->user, ['opening_balance' => '1000.00']);
        $mpesa = $this->makeAccount($this->user);
        $bank = $this->makeAccount($this->user);

        $this->transfer($cash->id, $mpesa->id, '100', ['description' => 'Airtime float']);
        $voidId = $this->transfer($cash->id, $bank->id, '200', ['description' => 'Bank deposit'])->json('data.id');
        $this->postJson("/api/transactions/{$voidId}/void", ['reason' => 'Mistake'])->assertOk();

        $this->getJson('/api/transactions')->assertJsonCount(2, 'data');
        $this->getJson('/api/transactions?status=posted')->assertJsonCount(1, 'data');
        $this->getJson("/api/transactions?account_id={$bank->id}")->assertJsonCount(1, 'data');
        $this->getJson('/api/transactions?search=airtime')->assertJsonCount(1, 'data');
        $this->getJson('/api/transactions?type=expense')->assertJsonCount(0, 'data');
    }

    public function test_another_users_transactions_are_invisible(): void
    {
        $other = $this->makeUser();
        $a = $this->makeAccount($other, ['opening_balance' => '100.00']);
        $b = $this->makeAccount($other);

        $this->actingAs($other);
        $id = $this->transfer($a->id, $b->id, '10')->assertCreated()->json('data.id');

        $this->actingAs($this->user);
        $this->getJson("/api/transactions/{$id}")->assertNotFound();
        $this->postJson("/api/transactions/{$id}/void", ['reason' => 'Nope'])->assertNotFound();
        $this->getJson('/api/transactions')->assertJsonCount(0, 'data');
    }
}
