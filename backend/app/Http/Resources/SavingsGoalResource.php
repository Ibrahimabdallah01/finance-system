<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SavingsGoalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $figures = $this->resource->getAttribute('figures');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'goal_type' => $this->goal_type,
            'target_amount' => $this->target_amount,
            'target_date' => $this->target_date?->toDateString(),
            'status' => $this->status,
            'notes' => $this->notes,
            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'scope' => $this->account->scope->value,
            ] : null),
            'account_id' => $this->account_id,
            'figures' => $this->when($figures !== null, fn () => $figures),
            'contributions' => $this->whenLoaded('contributions', fn () => $this->contributions->map(fn ($c) => [
                'id' => $c->id,
                'amount' => $c->amount, // + saved, - withdrawn
                'contribution_date' => $c->contribution_date->toDateString(),
                'note' => $c->note,
                'transaction_id' => $c->transaction_id,
                'transaction_status' => $c->relationLoaded('transaction') ? $c->transaction?->status : null,
            ])->values()),
        ];
    }
}
