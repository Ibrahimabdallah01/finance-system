<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Has no user_id: it is always reached through its sale, which is owner-scoped. */
class SaleItem extends Model
{
    protected $fillable = ['product_id', 'inventory_unit_id', 'quantity', 'unit_price', 'unit_cost_snapshot', 'returned_quantity'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'returned_quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'unit_cost_snapshot' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'inventory_unit_id');
    }
}
