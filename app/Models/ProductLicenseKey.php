<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductLicenseKey extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['key_encrypted'];

    protected function casts(): array
    {
        return [
            'key_encrypted' => 'encrypted',
            'assigned_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
