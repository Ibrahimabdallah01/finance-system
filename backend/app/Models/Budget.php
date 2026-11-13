<?php

namespace App\Models;

use App\Enums\Scope;
use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Budget extends Model
{
    use BelongsToOwner;

    protected $fillable = ['scope', 'category_id', 'name', 'period', 'amount', 'start_date', 'end_date', 'is_active', 'notes'];

    protected function casts(): array
    {
        return [
            'scope' => Scope::class,
            'amount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'category_id');
    }
}
