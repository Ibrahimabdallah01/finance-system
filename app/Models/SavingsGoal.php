<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavingsGoal extends Model
{
    use BelongsToOwner;

    protected $fillable = ['name', 'goal_type', 'target_amount', 'target_date', 'account_id', 'status', 'notes'];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'target_date' => 'date',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(GoalContribution::class)->orderByDesc('contribution_date')->orderByDesc('id');
    }
}
