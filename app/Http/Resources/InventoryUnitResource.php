<?php

namespace App\Http\Resources;

use App\Enums\UnitStatus;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sold = $this->status === UnitStatus::Sold && $this->resource->relationLoaded('saleItems')
            ? $this->saleItems->filter(fn ($item) => $item->sale?->status === 'completed')->last()
            : null;

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'brand' => $this->product->brand,
                'model' => $this->product->model,
            ]),
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier ? [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ] : null),
            'imei' => $this->imei,
            'serial_number' => $this->serial_number,
            'storage' => $this->storage,
            'color' => $this->color,
            'item_condition' => $this->item_condition->value,
            'acquisition_source' => $this->acquisition_source,
            'status' => $this->status->value,
            'purchase_date' => $this->purchase_date->toDateString(),
            'purchase_price' => $this->purchase_price,
            'additional_costs' => $this->additional_costs,
            'cost' => $this->cost(),
            'purchase_transaction_id' => $this->purchase_transaction_id,
            'debt_id' => $this->debt_id,
            'notes' => $this->notes,
            // Gross profit on this phone = selling price - cost, before discount and direct selling costs.
            'sale' => $sold ? [
                'sale_id' => $sold->sale_id,
                'sale_number' => $sold->sale->sale_number,
                'sale_date' => $sold->sale->sale_date->toDateString(),
                'unit_price' => $sold->unit_price,
                'gross_profit' => Money::sub($sold->unit_price, $sold->unit_cost_snapshot),
            ] : null,
        ];
    }
}
