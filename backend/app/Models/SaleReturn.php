<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

class SaleReturn extends Model
{
    use BelongsToOwner;

    protected $fillable = ['sale_item_id', 'quantity', 'return_date', 'return_amount', 'restock', 'reason', 'notes'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'return_date' => 'date',
            'return_amount' => 'decimal:2',
            'restock' => 'boolean',
        ];
    }
}
