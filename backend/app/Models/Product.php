<?php

namespace App\Models;

use App\Enums\Tracking;
use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToOwner, SoftDeletes;

    // quantity_on_hand and average_cost are caches maintained only by InventoryService.
    protected $fillable = [
        'product_type', 'tracking', 'name', 'brand', 'model', 'sku',
        'default_selling_price', 'reorder_level', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'tracking' => Tracking::class,
            'default_selling_price' => 'decimal:2',
            'average_cost' => 'decimal:2',
            'quantity_on_hand' => 'integer',
            'reorder_level' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function units(): HasMany
    {
        return $this->hasMany(InventoryUnit::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
