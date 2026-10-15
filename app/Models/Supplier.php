<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use BelongsToOwner;

    protected $fillable = ['name', 'phone', 'notes'];
}
