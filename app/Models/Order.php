<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Order extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'refunded_minor' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** Anything that is not a UUID cannot be an order: 404 instead of a PostgreSQL type error. */
    public function resolveRouteBinding($value, $field = null)
    {
        if (($field === null || $field === 'public_id') && ! Str::isUuid((string) $value)) {
            return null;
        }

        return parent::resolveRouteBinding($value, $field);
    }

    /** Short reference shown to people, e.g. "3f2c9a1e". */
    public function shortId(): string
    {
        return substr((string) $this->public_id, 0, 8);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function charges(): HasMany
    {
        return $this->payments()->where('kind', PaymentKind::Charge->value);
    }

    public function refunds(): HasMany
    {
        return $this->payments()->where('kind', PaymentKind::Refund->value);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function refundableMinor(): int
    {
        return $this->total_minor - $this->refunded_minor;
    }
}
