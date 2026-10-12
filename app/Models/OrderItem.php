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
            'download_count' => 'integer',
            'access_expires_at' => 'datetime',
            'renewal_reminded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
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

    /** The whole charge for this line went back to the buyer: no more downloads, keys or reviews. */
    public function isFullyRefunded(): bool
    {
        return $this->refunded_minor > 0 && $this->refunded_minor >= $this->netMinor();
    }

    public function accessExpired(): bool
    {
        return $this->access_expires_at !== null && $this->access_expires_at->isPast();
    }

    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }
}
