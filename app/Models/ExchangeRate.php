<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reference_at' => 'datetime',
        ];
    }
}
