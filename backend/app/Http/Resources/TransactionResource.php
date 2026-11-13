<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'scope' => $this->scope->value,
            'status' => $this->status,
            'transaction_date' => $this->transaction_date->toDateString(),
            'amount' => $this->amount,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category === null ? null : [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'parent_id' => $this->category->parent_id,
            ]),
            'receipts_count' => $this->whenCounted('receipts'),
            'sale_id' => $this->sale_id,
            'debt_id' => $this->debt_id,
            'payment_method' => $this->payment_method,
            'description' => $this->description,
            'recipient' => $this->recipient,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'entries' => LedgerEntryResource::collection($this->whenLoaded('entries')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
