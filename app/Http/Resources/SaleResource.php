<?php

namespace App\Http\Resources;

use App\Enums\TransactionType;
use App\Services\SaleCalculator;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $transactions = fn (array $types) => $this->transactions
            ->filter(fn ($t) => in_array($t->type, $types, true))
            ->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type->value,
                'amount' => $t->amount,
                'transaction_date' => $t->transaction_date->toDateString(),
                'status' => $t->status,
                'payment_method' => $t->payment_method,
                'reference' => $t->reference,
                'description' => $t->description,
                'recipient' => $t->recipient,
            ])->values();

        return [
            'id' => $this->id,
            'sale_number' => $this->sale_number,
            'status' => $this->status,
            'sale_date' => $this->sale_date->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'notes' => $this->notes,
            'cancel_reason' => $this->cancel_reason,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ] : null),
            'figures' => SaleCalculator::figures($this->resource),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product' => $item->relationLoaded('product') ? [
                    'name' => $item->product->name,
                    'tracking' => $item->product->tracking->value,
                ] : null,
                'unit' => $item->relationLoaded('unit') && $item->unit ? [
                    'id' => $item->unit->id,
                    'imei' => $item->unit->imei,
                    'serial_number' => $item->unit->serial_number,
                    'storage' => $item->unit->storage,
                    'color' => $item->unit->color,
                ] : null,
                'quantity' => $item->quantity,
                'returned_quantity' => $item->returned_quantity,
                'unit_price' => $item->unit_price,
                'unit_cost_snapshot' => $item->unit_cost_snapshot,
                'line_total' => Money::mul($item->unit_price, $item->quantity),
            ])->values()),
            'returns' => $this->whenLoaded('returns', fn () => $this->returns->map(fn ($r) => [
                'id' => $r->id,
                'sale_item_id' => $r->sale_item_id,
                'quantity' => $r->quantity,
                'return_date' => $r->return_date->toDateString(),
                'return_amount' => $r->return_amount,
                'restock' => $r->restock,
                'reason' => $r->reason,
            ])->values()),
            'payments' => $this->whenLoaded('transactions', fn () => $transactions([
                TransactionType::CustomerPayment, TransactionType::CustomerRefund,
            ])),
            'costs' => $this->whenLoaded('transactions', fn () => $transactions([
                TransactionType::Expense, TransactionType::InventoryPurchase,
            ])),
        ];
    }
}
