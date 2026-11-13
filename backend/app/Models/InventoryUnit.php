<?php

namespace App\Models;

use App\Enums\ItemCondition;
use App\Enums\UnitStatus;
use App\Models\Concerns\BelongsToOwner;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryUnit extends Model
{
    use BelongsToOwner;

    protected $fillable = [
        'supplier_id', 'imei', 'serial_number', 'storage', 'color', 'item_condition',
        'purchase_date', 'purchase_price', 'additional_costs', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'item_condition' => ItemCondition::class,
            'status' => UnitStatus::class,
            'purchase_date' => 'date',
            'purchase_price' => 'decimal:2',
            'additional_costs' => 'decimal:2',
        ];
    }

    /** Cost basis: purchase price plus additional acquisition costs. */
    public function cost(): string
    {
        return Money::add($this->purchase_price, $this->additional_costs);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }
}
