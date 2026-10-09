<?php

namespace App\Models;

use App\Enums\DeliveryType;
use App\Enums\ProductStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'stock' => 'integer',
            'status' => ProductStatus::class,
            'delivery_type' => DeliveryType::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** All files, including retired ones that past buyers can still download. */
    public function files(): HasMany
    {
        return $this->hasMany(ProductFile::class);
    }

    /** Files delivered to new orders. */
    public function activeFiles(): HasMany
    {
        return $this->files()->whereNull('retired_at');
    }

    public function licenseKeys(): HasMany
    {
        return $this->hasMany(ProductLicenseKey::class);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Active->value);
    }

    public function isPurchasable(): bool
    {
        return $this->status === ProductStatus::Active && ($this->stock === null || $this->stock > 0);
    }

    public function hasUnlimitedStock(): bool
    {
        return $this->stock === null;
    }
}
