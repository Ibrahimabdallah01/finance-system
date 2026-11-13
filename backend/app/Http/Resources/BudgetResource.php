<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $performance = $this->resource->getAttribute('performance');

        return [
            'id' => $this->id,
            'scope' => $this->scope->value,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ] : null),
            'category_id' => $this->category_id,
            'name' => $this->name,
            'period' => $this->period,
            'amount' => $this->amount,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'performance' => $this->when($performance !== null, fn () => $performance),
        ];
    }
}
