<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

class Business extends Model
{
    use BelongsToOwner;

    protected $fillable = ['name', 'currency', 'financial_start_date'];

    protected function casts(): array
    {
        return ['financial_start_date' => 'date'];
    }
}
