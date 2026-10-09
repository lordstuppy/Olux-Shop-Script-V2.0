<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['delivered_payload'];

    protected function casts(): array
    {
        return [
            'unit_price_minor' => 'integer',
            'quantity' => 'integer',
            'list_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'refunded_minor' => 'integer',
            'commission_bps' => 'integer',
            'seller_earning_minor' => 'integer',
            // Licence keys and seller-provided secrets are encrypted at rest.
            'delivered_payload' => 'encrypted:array',
            'delivered_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function grossMinor(): int
    {
        return $this->unit_price_minor * $this->quantity;
    }

    /** Amount actually charged for this line after its share of the discount. */
    public function netMinor(): int
    {
        return $this->grossMinor() - $this->discount_minor;
    }

    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }
}
