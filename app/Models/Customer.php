<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToOwner;

    protected $fillable = ['name', 'phone', 'email', 'notes'];

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
