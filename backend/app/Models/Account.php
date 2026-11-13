<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\Scope;
use App\Models\Concerns\BelongsToOwner;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use BelongsToOwner;

    protected $fillable = [
        'scope', 'name', 'type', 'provider', 'opening_balance',
        'opening_balance_date', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'scope' => Scope::class,
            'type' => AccountType::class,
            'opening_balance' => 'decimal:2',
            'opening_balance_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(AccountReconciliation::class);
    }

    /**
     * Adds `ledger_total` (sum of POSTED ledger entries, optionally up to a date).
     * The balance accessor then returns opening_balance + ledger_total.
     */
    public function scopeWithBalance(Builder $query, ?string $asOf = null): Builder
    {
        $ledgerTotal = LedgerEntry::query()
            ->selectRaw('COALESCE(SUM(ledger_entries.amount), 0)')
            ->join('transactions', 'transactions.id', '=', 'ledger_entries.transaction_id')
            ->whereColumn('ledger_entries.account_id', 'accounts.id')
            ->where('transactions.status', 'posted');

        if ($asOf !== null) {
            $ledgerTotal->where('ledger_entries.entry_date', '<=', $asOf);
        }

        return $query->select('accounts.*')->addSelect(['ledger_total' => $ledgerTotal]);
    }

    /** Null unless the query used withBalance(). */
    protected function balance(): Attribute
    {
        return Attribute::get(function ($value, array $attributes) {
            if (! array_key_exists('ledger_total', $attributes)) {
                return null;
            }

            return Money::add(
                Money::fromDatabase($attributes['opening_balance']),
                Money::fromDatabase($attributes['ledger_total'])
            );
        });
    }

    /** Plain values for the audit log. */
    public function auditSnapshot(): array
    {
        return [
            'scope' => $this->scope->value,
            'name' => $this->name,
            'type' => $this->type->value,
            'provider' => $this->provider,
            'opening_balance' => $this->opening_balance,
            'opening_balance_date' => $this->opening_balance_date->toDateString(),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
        ];
    }
}
