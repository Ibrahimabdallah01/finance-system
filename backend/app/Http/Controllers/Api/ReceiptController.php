<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\StoreReceiptRequest;
use App\Http\Resources\ReceiptResource;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Services\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceiptController extends Controller
{
    public function index(Transaction $transaction)
    {
        return ReceiptResource::collection($transaction->receipts()->orderBy('id')->get());
    }

    public function store(StoreReceiptRequest $request, Transaction $transaction, ReceiptService $service): JsonResponse
    {
        $receipt = $service->attach($transaction, $request->file('file'));

        return (new ReceiptResource($receipt))->response()->setStatusCode(201);
    }

    /** Streams the private file as a download, only to its owner. */
    public function download(Receipt $receipt): StreamedResponse
    {
        $disk = Storage::disk($receipt->disk);

        abort_unless($disk->exists($receipt->path), 404);

        return $disk->download($receipt->path, $receipt->original_name, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Receipt $receipt, ReceiptService $service): Response
    {
        $service->remove($receipt);

        return response()->noContent();
    }
}
