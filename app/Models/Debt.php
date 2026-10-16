<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Loans and supplier credit. Customer balances on sales are not stored here:
 * they come from the sales themselves (see SaleCalculator).
 */
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

    /** Loan transactions, principal repayments and interest linked to this debt. */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class)->orderBy('transaction_date')->orderBy('id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(DebtInstallment::class)->orderBy('due_date')->orderBy('id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
