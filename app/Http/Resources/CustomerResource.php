<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'notes' => $this->notes,
            // Only on the single-customer view: unpaid balance across completed sales.
            'outstanding_balance' => $this->when($this->resource->getAttribute('outstanding_balance') !== null,
                fn () => $this->resource->getAttribute('outstanding_balance')),
        ];
    }
}
