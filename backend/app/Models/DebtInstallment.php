<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Planned repayment. Has no user_id: always reached through its debt. */
class DebtInstallment extends Model
{
    protected $fillable = ['due_date', 'amount_due', 'note'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'amount_due' => 'decimal:2',
        ];
    }
}
