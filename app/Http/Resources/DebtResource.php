<?php

namespace App\Http\Resources;

use App\Enums\TransactionType;
use App\Services\DebtCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DebtResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $figures = DebtCalculator::figures($this->resource);

        $list = fn (array $types) => $this->transactions
            ->filter(fn ($t) => in_array($t->type, $types, true))
            ->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type->value,
                'amount' => $t->amount,
                'transaction_date' => $t->transaction_date->toDateString(),
                'status' => $t->status,
                'description' => $t->description,
            ])->values();

        return [
            'id' => $this->id,
            'scope' => $this->scope,
            'direction' => $this->direction, // receivable = owed to me, payable = I owe
            'source' => $this->source,
            'party_name' => $this->party_name,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier ? ['id' => $this->supplier->id, 'name' => $this->supplier->name] : null),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'name' => $this->customer->name] : null),
            'original_amount' => $this->original_amount,
            'issued_on' => $this->issued_on->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'notes' => $this->notes,
            'figures' => $figures,
            'repayments' => $list([TransactionType::DebtRepaymentIn, TransactionType::DebtRepaymentOut]),
            'interest' => $list([TransactionType::Expense, TransactionType::Income]),
        ];
    }
}
