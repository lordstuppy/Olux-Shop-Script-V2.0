<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the catalog. Only products with status "active" are visible.
 */
class CatalogService
{
    public function __construct(private readonly CurrencyConverter $converter) {}

    public const SORTS = ['newest', 'best', 'price_asc', 'price_desc', 'title'];

    /**
     * @param  array{q?: ?string, category?: ?string, sort?: ?string, currency?: ?string, seller?: ?int, price_min?: ?string, price_max?: ?string, price_currency?: ?string}  $filters
     */
    public function paginate(array $filters, ?int $perPage = null): LengthAwarePaginator
    {
        $query = Product::query()->visible()->with(['category', 'seller', 'images']);

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $this->applySearch($query, $term);
        }

        $categorySlug = $filters['category'] ?? null;
        if ($categorySlug) {
            $query->whereHas('category', fn (Builder $q) => $q->where('slug', $categorySlug));
        }

        $currency = $filters['currency'] ?? null;
        if ($currency) {
            $query->where('currency', $currency);
        }

        if (! empty($filters['seller'])) {
            $query->where('seller_id', (int) $filters['seller']);
        }

        $this->applyPriceRange($query, $filters['price_min'] ?? null, $filters['price_max'] ?? null, $filters['price_currency'] ?? (string) config('shop.default_currency'));

        match ($filters['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('price_minor')->orderBy('id'),
            'price_desc' => $query->orderByDesc('price_minor')->orderBy('id'),
            'title' => $query->orderBy('title')->orderBy('id'),
            'best' => $query->orderByDesc($this->unitsSold())->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        return $query->paginate($perPage ?? (int) config('shop.products_per_page'))->withQueryString();
    }

    public function findBySlug(string $slug): Product
    {
        return Product::query()->visible()->with(['category', 'seller.sellerProfile', 'files', 'images'])
            ->where('slug', $slug)->firstOrFail();
    }

    /** @return Collection<int, Category> */
    public function categories(): Collection
    {
        return Category::query()->orderBy('name')->get();
    }

    /** @return Collection<int, Product> */
    public function latest(int $limit = 6): Collection
    {
        return Product::query()->visible()->with(['category', 'images'])->latest()->limit($limit)->get();
    }

    /**
     * Other listed products from the same category, best sellers first.
     *
     * @return Collection<int, Product>
     */
    public function related(Product $product, int $limit = 4): Collection
    {
        if ($product->category_id === null) {
            return new Collection;
        }

        return Product::query()->visible()->with(['category', 'images'])
            ->where('category_id', $product->category_id)->whereKeyNot($product->id)
            ->orderByDesc($this->unitsSold())->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Price range in the shopper's currency. Each listing currency gets its
     * own bounds, converted with the configured rate (rounded so that a
     * listing shown at exactly the bound is included); listings in a
     * currency without a rate cannot be compared and are left out.
     */
    private function applyPriceRange(Builder $query, ?string $min, ?string $max, string $in): void
    {
        $min = $min !== null && $min !== '' ? BigDecimal::of($min) : null;
        $max = $max !== null && $max !== '' ? BigDecimal::of($max) : null;
        if ($min === null && $max === null) {
            return;
        }

        $query->where(function (Builder $outer) use ($min, $max, $in) {
            foreach (Money::supported() as $listing) {
                $rate = $listing === $in ? '1' : $this->converter->rate($listing, $in);
                if ($rate === null || BigDecimal::of($rate)->isZero()) {
                    continue;
                }
                $scale = Money::exponent($listing);
                $outer->orWhere(function (Builder $q) use ($listing, $rate, $min, $max, $scale) {
                    $q->where('currency', $listing);
                    if ($min !== null) {
                        $q->where('price_minor', '>=', $min->dividedBy($rate, $scale, RoundingMode::Down)->withPointMovedRight($scale)->toInt());
                    }
                    if ($max !== null) {
                        $q->where('price_minor', '<=', $max->dividedBy($rate, $scale, RoundingMode::Up)->withPointMovedRight($scale)->toInt());
                    }
                });
            }
        });
    }

    /** Approved sellers with at least one visible product, for the seller filter. */
    public function sellers(): \Illuminate\Support\Collection
    {
        return DB::table('seller_profiles')->where('status', 'approved')
            ->whereExists(fn ($q) => $q->from('products')->whereColumn('products.seller_id', 'seller_profiles.user_id')->where('products.status', 'active'))
            ->orderBy('display_name')->pluck('display_name', 'user_id');
    }

    /** Units sold in paid orders, as a sortable subquery. */
    private function unitsSold(): \Illuminate\Database\Query\Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereColumn('order_items.product_id', 'products.id')
            ->whereIn('orders.status', ['paid', 'delivered', 'partially_refunded'])
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0)');
    }

    /**
     * Small result set for the optional live-search enhancement.
     *
     * @return Collection<int, Product>
     */
    public function suggest(string $term, int $limit = 8): Collection
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return new Collection;
        }

        $query = Product::query()->visible()->select(['id', 'slug', 'title']);
        $this->applySearch($query, $term);

        return $query->orderBy('title')->limit($limit)->get();
    }

    private function applySearch(Builder $query, string $term): void
    {
        $term = mb_substr($term, 0, 100);
        // Escape LIKE wildcards so user input is matched literally.
        $like = '%'.addcslashes($term, '%_\\').'%';
        $query->where(function (Builder $q) use ($like) {
            $q->where('title', 'ILIKE', $like)->orWhere('description', 'ILIKE', $like);
        });
    }
}
