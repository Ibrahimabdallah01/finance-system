<?php

namespace App\Models;

use App\Enums\Scope;
use App\Enums\TransactionType;
use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use BelongsToOwner;

    protected $fillable = [
        'scope', 'type', 'status', 'transaction_date', 'amount', 'category_id', 'sale_id',
        'debt_id', 'payment_method', 'description', 'recipient', 'reference', 'notes',
        'voided_at', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'scope' => Scope::class,
            'type' => TransactionType::class,
            'amount' => 'decimal:2',
            'transaction_date' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'category_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }
}
