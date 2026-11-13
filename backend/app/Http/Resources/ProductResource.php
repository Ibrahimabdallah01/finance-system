<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $stock = $this->resource->getAttribute('stock_info');
        $counted = $this->tracking->value === 'quantity';

        return [
            'id' => $this->id,
            'product_type' => $this->product_type,
            'tracking' => $this->tracking->value,
            'name' => $this->name,
            'brand' => $this->brand,
            'model' => $this->model,
            'sku' => $this->sku,
            'default_selling_price' => $this->default_selling_price,
            'reorder_level' => $this->reorder_level,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'average_cost' => $counted ? $this->average_cost : null,
            'stock' => $this->when($stock !== null, fn () => [
                'quantity' => $stock['quantity'],
                'value_at_cost' => $stock['value'],
                'low_stock' => $counted && $this->reorder_level !== null && $stock['quantity'] <= $this->reorder_level,
            ]),
        ];
    }
}
