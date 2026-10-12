<?php

namespace App\Models;

use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => PaymentKind::class,
            'provider' => PaymentProvider::class,
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'received_minor' => 'integer',
            'raw_payload_json' => 'array',
            'confirmed_at' => 'datetime',
            'quoted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'parent_payment_id');
    }
}
