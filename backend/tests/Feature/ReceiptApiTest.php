<?php

namespace Tests\Feature;

use App\Enums\Scope;
use App\Enums\TransactionType;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\ReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesFinanceData;
use Tests\TestCase;

class ReceiptApiTest extends TestCase
{
    use CreatesFinanceData, RefreshDatabase;

    private User $user;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = $this->makeUser();
        $this->actingAs($this->user);
        $this->transaction = $this->makeExpenseFor($this->user);
    }

    private function makeExpenseFor(User $user): Transaction
    {
        $account = $this->makeAccount($user, ['scope' => Scope::Personal, 'opening_balance' => '1000.00']);
        $category = $this->makeCategory($user);

        return app(LedgerService::class)->post([
            'type' => TransactionType::Expense, 'account_id' => $account->id, 'category_id' => $category->id,
            'amount' => '10.00', 'transaction_date' => $this->daysAgo(1), 'description' => 'Test',
        ]);
    }

    private function upload(Transaction $transaction, UploadedFile $file)
    {
        return $this->post("/api/transactions/{$transaction->id}/receipts", ['file' => $file], ['Accept' => 'application/json']);
    }

    public function test_a_receipt_is_stored_privately_with_a_random_name(): void
    {
        $this->upload($this->transaction, UploadedFile::fake()->create('my receipt.pdf', 100, 'application/pdf'))
            ->assertCreated()
            ->assertJsonPath('data.original_name', 'my receipt.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf');

        $receipt = Receipt::query()->firstOrFail();

        $this->assertStringStartsWith("receipts/{$this->user->id}/", $receipt->path);
        $this->assertStringNotContainsString('receipt', basename($receipt->path));
        Storage::disk('local')->assertExists($receipt->path);

        $this->getJson("/api/transactions/{$this->transaction->id}")->assertJsonPath('data.receipts_count', 1);
        $this->getJson("/api/transactions/{$this->transaction->id}/receipts")->assertJsonCount(1, 'data');
    }

    public function test_the_same_file_cannot_be_attached_twice(): void
    {
        $this->upload($this->transaction, UploadedFile::fake()->createWithContent('a.pdf', 'identical content'))->assertCreated();

        $this->upload($this->transaction, UploadedFile::fake()->createWithContent('b.pdf', 'identical content'))
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_only_images_and_pdfs_up_to_5_mb_are_accepted(): void
    {
        $this->upload($this->transaction, UploadedFile::fake()->create('evil.php', 10, 'application/x-php'))
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload($this->transaction, UploadedFile::fake()->create('notes.txt', 10, 'text/plain'))
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload($this->transaction, UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'))
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame(0, Receipt::query()->count());
    }

    public function test_a_transaction_has_at_most_ten_receipts(): void
    {
        foreach (range(1, 10) as $i) {
            $this->upload($this->transaction, UploadedFile::fake()->createWithContent("r{$i}.pdf", "content {$i}"))->assertCreated();
        }

        $this->upload($this->transaction, UploadedFile::fake()->createWithContent('r11.pdf', 'content 11'))
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_the_owner_can_download_the_file(): void
    {
        $id = $this->upload($this->transaction, UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'))->json('data.id');

        $this->get("/api/receipts/{$id}/download")
            ->assertOk()
            ->assertDownload('receipt.pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_receipt_can_be_deleted_with_its_file(): void
    {
        $id = $this->upload($this->transaction, UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'))->json('data.id');
        $path = Receipt::query()->findOrFail($id)->path;

        $this->deleteJson("/api/receipts/{$id}")->assertNoContent();

        $this->assertDatabaseMissing('receipts', ['id' => $id]);
        Storage::disk('local')->assertMissing($path);

        $events = collect($this->getJson("/api/transactions/{$this->transaction->id}/history")->json('data'))->pluck('event')->all();
        $this->assertSame(['created', 'receipt_added', 'receipt_removed'], $events);
    }

    public function test_other_users_receipts_are_out_of_reach(): void
    {
        $other = $this->makeUser();
        $this->actingAs($other);
        $theirTransaction = $this->makeExpenseFor($other);
        $receipt = app(ReceiptService::class)->attach($theirTransaction, UploadedFile::fake()->create('secret.pdf', 10, 'application/pdf'));

        $this->actingAs($this->user);

        $this->get("/api/receipts/{$receipt->id}/download", ['Accept' => 'application/json'])->assertNotFound();
        $this->deleteJson("/api/receipts/{$receipt->id}")->assertNotFound();
        $this->getJson("/api/transactions/{$theirTransaction->id}/receipts")->assertNotFound();
        $this->upload($theirTransaction, UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'))->assertNotFound();
    }
}
