<?php

namespace App\Models;

use App\Enums\DeliveryType;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
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
            'access_days' => 'integer',
            'download_limit' => 'integer',
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

    /** Files that can be delivered to new orders: not retired and scanned clean (or scanning disabled). */
    public function activeFiles(): HasMany
    {
        return $this->files()->whereNull('retired_at')->whereIn('scan_status', ['clean', 'skipped']);
    }

    /** Files still attached to the product, whatever their scan state. */
    public function currentFiles(): HasMany
    {
        return $this->files()->whereNull('retired_at');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function visibleReviews(): HasMany
    {
        return $this->reviews()->where('status', 'visible')->latest('id');
    }

    public function downloadLimit(): int
    {
        return $this->download_limit ?? (int) config('shop.max_downloads_per_item');
    }

    public function licenseKeys(): HasMany
    {
        return $this->hasMany(ProductLicenseKey::class);
    }

    /** Instant products need a current file or licence keys; manual ones are delivered by the seller. */
    public function hasDeliverableContent(): bool
    {
        return $this->delivery_type !== DeliveryType::Instant
            || $this->currentFiles()->exists()
            || $this->licenseKeys()->exists();
    }

    /** Listed products: active, and their seller's account is active and still allowed to sell (not demoted to buyer). */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('products.status', ProductStatus::Active->value)
            ->whereExists(fn ($q) => $q->from('users')->whereColumn('users.id', 'products.seller_id')
                ->where('users.status', UserStatus::Active->value)->where('users.role', '!=', UserRole::Buyer->value));
    }

    public function isPurchasable(): bool
    {
        if ($this->status !== ProductStatus::Active || ($this->stock !== null && $this->stock <= 0)) {
            return false;
        }
        // Lists eager-load the seller; checking it in memory saves one query per
        // product (wishlist, cart). Otherwise ask the database.
        if ($this->relationLoaded('seller')) {
            return $this->seller !== null && $this->seller->status === UserStatus::Active && $this->seller->role !== UserRole::Buyer;
        }

        return $this->seller()->where('status', UserStatus::Active->value)->where('role', '!=', UserRole::Buyer->value)->exists();
    }

    public function isSoldOut(): bool
    {
        return $this->stock !== null && $this->stock <= 0;
    }
}
