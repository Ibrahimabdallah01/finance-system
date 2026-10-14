<?php

namespace App\Models;

use App\Enums\CategoryKind;
use App\Enums\Scope;
use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransactionCategory extends Model
{
    use BelongsToOwner;

    protected $fillable = ['parent_id', 'scope', 'kind', 'name', 'is_system', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'scope' => Scope::class,
            'kind' => CategoryKind::class,
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'category_id');
    }

    public function auditSnapshot(): array
    {
        return [
            'scope' => $this->scope->value,
            'kind' => $this->kind->value,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
