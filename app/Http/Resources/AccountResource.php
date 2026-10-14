<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope->value,
            'name' => $this->name,
            'type' => $this->type->value,
            'provider' => $this->provider,
            'opening_balance' => $this->opening_balance,
            'opening_balance_date' => $this->opening_balance_date->toDateString(),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            // Present only when the query used withBalance().
            'balance' => $this->when($this->balance !== null, fn () => $this->balance),
        ];
    }
}
