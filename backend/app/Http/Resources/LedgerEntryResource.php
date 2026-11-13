<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'scope' => $this->account->scope->value,
            ]),
            'amount' => $this->amount, // signed: + money in, - money out
            'entry_date' => $this->entry_date->toDateString(),
        ];
    }
}
