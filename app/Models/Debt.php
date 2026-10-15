<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

/** Full debts module (repayments, schedules) comes in Phase 7; Phase 6 creates supplier-credit debts. */
class Debt extends Model
{
    use BelongsToOwner;

    protected $fillable = [
        'scope', 'direction', 'source', 'party_name', 'supplier_id', 'customer_id',
        'original_amount', 'issued_on', 'due_date', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:2',
            'issued_on' => 'date',
            'due_date' => 'date',
        ];
    }
}
