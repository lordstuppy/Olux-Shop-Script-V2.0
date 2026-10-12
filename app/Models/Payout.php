<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'amount_minor' => 'integer',
            'attempt' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /** Shkeeper external id of the current transfer attempt: payout-{id}, then payout-{id}-a2, -a3, ... */
    public function externalId(): string
    {
        return 'payout-'.$this->id.((int) $this->attempt > 1 ? '-a'.$this->attempt : '');
    }

    /** @return BelongsTo<User, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
}
