<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountReconciliation extends Model
{
    use BelongsToOwner;

    protected $fillable = [
        'account_id', 'reconciled_on', 'recorded_balance', 'actual_balance',
        'difference', 'adjustment_transaction_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'reconciled_on' => 'date',
            'recorded_balance' => 'decimal:2',
            'actual_balance' => 'decimal:2',
            'difference' => 'decimal:2',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'adjustment_transaction_id');
    }
}
