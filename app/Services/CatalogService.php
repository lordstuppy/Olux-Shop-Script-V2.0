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
        $query = Product::query()->visible()->with(['category', 'images']);

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
            'best' => $query->orderByDesc('units_sold')->orderByDesc('id'),
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
            ->orderByDesc('units_sold')->orderByDesc('id')->limit($limit)->get();
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

    public const FILTER_SELLERS = 50;

    /**
     * Sellers for the catalogue's seller filter: approved sellers with at least
     * one visible product, the best-selling FILTER_SELLERS of them (by units
     * sold, see refreshSalesRanking) plus $selected, ordered by name. Every
     * seller used to be listed, which put 28 KB of options into every
     * catalogue page at 500 sellers; any seller's products stay reachable
     * through "More from this seller" on their product pages.
     *
     * @return \Illuminate\Support\Collection<int, string> display name by seller id
     */
    public function sellers(?int $selected = null): \Illuminate\Support\Collection
    {
        $eligible = fn () => DB::table('seller_profiles')->where('seller_profiles.status', 'approved')
            ->join('products', 'products.seller_id', '=', 'seller_profiles.user_id')->where('products.status', 'active')
            ->join('users', 'users.id', '=', 'seller_profiles.user_id')->where('users.status', 'active')->where('users.role', '!=', 'buyer')
            ->groupBy('seller_profiles.user_id', 'seller_profiles.display_name');

        $sellers = $eligible()->orderByRaw('SUM(products.units_sold) DESC')->orderBy('seller_profiles.user_id')
            ->limit(self::FILTER_SELLERS)->pluck('seller_profiles.display_name', 'seller_profiles.user_id');
        if ($selected !== null && ! $sellers->has($selected)) {
            $sellers = $sellers->union($eligible()->where('seller_profiles.user_id', $selected)->pluck('seller_profiles.display_name', 'seller_profiles.user_id'));
        }

        return $sellers->sortBy(fn (string $name) => mb_strtolower($name));
    }

    /**
     * Recomputes products.units_sold (units in paid, delivered or partly
     * refunded orders) for the "best selling" sort. Run every ten minutes by
     * shop:refresh-sales-ranking: rankings may lag sales by that much, in
     * exchange for catalogue pages that do not sum all order lines per view.
     * Only rows whose figure changed are written.
     */
    public function refreshSalesRanking(): int
    {
        return DB::update(<<<'SQL'
            UPDATE products SET units_sold = s.units
            FROM (SELECT products.id, COALESCE(sold.units, 0) AS units FROM products
                  LEFT JOIN (SELECT order_items.product_id, SUM(order_items.quantity) AS units FROM order_items
                             JOIN orders ON orders.id = order_items.order_id
                             WHERE orders.status IN ('paid', 'delivered', 'partially_refunded')
                             GROUP BY order_items.product_id) sold ON sold.product_id = products.id) s
            WHERE s.id = products.id AND products.units_sold <> s.units
            SQL);
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
