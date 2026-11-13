<?php

namespace App\Services;

use App\Models\Receipt;
use App\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** Receipts live on the private disk and are only served through an authorised endpoint. */
final class ReceiptService
{
    public const DISK = 'local';

    public const MAX_PER_TRANSACTION = 10;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function attach(Transaction $transaction, UploadedFile $file): Receipt
    {
        if ($transaction->receipts()->count() >= self::MAX_PER_TRANSACTION) {
            throw ValidationException::withMessages([
                'file' => ['A transaction can have at most '.self::MAX_PER_TRANSACTION.' receipts.'],
            ]);
        }

        $hash = hash_file('sha256', $file->getRealPath());

        if ($transaction->receipts()->where('sha256', $hash)->exists()) {
            throw ValidationException::withMessages(['file' => ['This file is already attached to the transaction.']]);
        }

        // The stored name is random: the user's file name never touches the file system.
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        $path = $file->storeAs('receipts/'.$transaction->user_id, Str::uuid()->toString().'.'.$extension, self::DISK);

        if ($path === false) {
            throw new RuntimeException('The receipt could not be stored.');
        }

        try {
            return DB::transaction(function () use ($transaction, $file, $path, $hash) {
                $receipt = new Receipt([
                    'disk' => self::DISK,
                    'path' => $path,
                    'original_name' => $this->safeName($file->getClientOriginalName()),
                    'mime_type' => (string) $file->getMimeType(),
                    'size_bytes' => (int) $file->getSize(),
                    'sha256' => $hash,
                ]);
                $receipt->transaction_id = $transaction->id;
                $receipt->user_id = $transaction->user_id;
                $receipt->save();

                $this->audit->record($transaction, 'receipt_added', null, [
                    'receipt_id' => $receipt->id,
                    'name' => $receipt->original_name,
                ]);

                return $receipt;
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($path);

            throw $e;
        }
    }

    public function remove(Receipt $receipt): void
    {
        $transaction = $receipt->transaction;

        DB::transaction(function () use ($receipt, $transaction) {
            $this->audit->record($transaction, 'receipt_removed', ['receipt_id' => $receipt->id, 'name' => $receipt->original_name], null);
            $receipt->delete();
        });

        Storage::disk($receipt->disk)->delete($receipt->path);
    }

    private function safeName(string $name): string
    {
        $clean = preg_replace('/[^\w.\- ]+/u', '_', basename($name)) ?? '';
        $clean = trim(Str::limit($clean, 120, ''));

        return $clean !== '' ? $clean : 'receipt';
    }
}
