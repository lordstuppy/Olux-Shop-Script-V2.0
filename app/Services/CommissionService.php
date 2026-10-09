<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The platform earns a commission on every sale. The rate comes from the
 * most specific level that sets one:
 *
 *   product override > seller rate > category rate > global default
 *
 * Rates are in basis points (1000 = 10.00%). OrderService freezes the
 * resolved rate on each order line, so later changes never touch past sales.
 */
class CommissionService
{
    public const SOURCES = ['product', 'seller', 'category', 'default'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{bps: int, source: string} */
    public function resolve(Product $product, ?SellerProfile $profile = null): array
    {
        if ($product->commission_bps !== null) {
            return ['bps' => (int) $product->commission_bps, 'source' => 'product'];
        }
        $profile ??= SellerProfile::query()->where('user_id', $product->seller_id)->first();
        if ($profile?->commission_bps !== null) {
            return ['bps' => (int) $profile->commission_bps, 'source' => 'seller'];
        }
        $category = $product->relationLoaded('category') ? $product->category : $product->category()->first();
        if ($category?->commission_bps !== null) {
            return ['bps' => (int) $category->commission_bps, 'source' => 'category'];
        }

        return ['bps' => (int) config('shop.commission_bps'), 'source' => 'default'];
    }

    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            'product' => __('product override'),
            'seller' => __('seller rate'),
            'category' => __('category rate'),
            default => __('global default'),
        };
    }

    /** Sets or clears (null) the rate on a product, seller profile or category. */
    public function set(Product|SellerProfile|Category $target, ?int $bps, User $actor): void
    {
        $old = $target->commission_bps;
        if ($old === $bps) {
            return;
        }
        $target->forceFill(['commission_bps' => $bps])->save();
        $this->audit->log('commission.changed', $target, [
            'level' => match (true) {
                $target instanceof Product => 'product',
                $target instanceof SellerProfile => 'seller',
                default => 'category',
            },
            'from' => $old,
            'to' => $bps,
        ], $actor);
    }

    public static function percent(int $bps): string
    {
        return number_format($bps / 100, 2).'%';
    }

    /** For audit targets and messages. */
    public static function describe(Model $target): string
    {
        return match (true) {
            $target instanceof Product => $target->title,
            $target instanceof SellerProfile => $target->display_name,
            $target instanceof Category => $target->name,
            default => (string) $target->getKey(),
        };
    }
}
