<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
