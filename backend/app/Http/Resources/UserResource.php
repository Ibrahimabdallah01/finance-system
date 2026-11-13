<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'business' => $this->whenLoaded('business', fn () => $this->business === null ? null : [
                'id' => $this->business->id,
                'name' => $this->business->name,
                'currency' => $this->business->currency,
                'financial_start_date' => $this->business->financial_start_date?->toDateString(),
            ]),
        ];
    }
}
