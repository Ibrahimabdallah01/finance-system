<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    use BelongsToOwner;

    protected $fillable = [
        'product_id', 'inventory_unit_id', 'movement_type', 'quantity', 'unit_cost', 'movement_date',
        'sale_id', 'sale_item_id', 'sale_return_id', 'transaction_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'movement_date' => 'date',
        ];
    }
}
