<?php

namespace App\Models;

use App\Enums\SellerProfileStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class SellerProfile extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['payout_change_token_hash'];

    protected function casts(): array
    {
        return [
            'status' => SellerProfileStatus::class,
            'reviewed_at' => 'datetime',
            'commission_bps' => 'integer',
            'payout_change_expires_at' => 'datetime',
            'payout_address_changed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Payouts wait for the cool-down after an address change (protects against account takeover). */
    public function payoutsBlockedUntil(): ?Carbon
    {
        if ($this->payout_address_changed_at === null) {
            return null;
        }
        $until = $this->payout_address_changed_at->copy()->addHours((int) config('shop.payout_address_cooldown_hours'));

        return $until->isFuture() ? $until : null;
    }

    public function effectiveCommissionBps(): int
    {
        return $this->commission_bps ?? (int) config('shop.commission_bps');
    }
}
