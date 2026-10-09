<?php

namespace App\Models;

use App\Enums\CouponType;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'value' => 'integer',
            'min_total_minor' => 'integer',
            'max_redemptions' => 'integer',
            'max_per_user' => 'integer',
            'redemptions_count' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }
}
