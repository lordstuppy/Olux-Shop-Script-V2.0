<?php

namespace App\Models;

use App\Enums\SellerProfileStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerProfile extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SellerProfileStatus::class,
            'reviewed_at' => 'datetime',
            'commission_bps' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function effectiveCommissionBps(): int
    {
        return $this->commission_bps ?? (int) config('shop.commission_bps');
    }
}
