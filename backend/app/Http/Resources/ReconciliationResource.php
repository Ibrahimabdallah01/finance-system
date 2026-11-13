<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReconciliationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'reconciled_on' => $this->reconciled_on->toDateString(),
            'recorded_balance' => $this->recorded_balance,
            'actual_balance' => $this->actual_balance,
            'difference' => $this->difference, // actual - recorded
            'adjustment_transaction_id' => $this->adjustment_transaction_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
