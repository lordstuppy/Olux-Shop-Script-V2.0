<?php

namespace App\Http\Controllers;

use App\Services\CartService;
use App\Services\CatalogService;
use App\Services\CurrencyConverter;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request, CatalogService $catalog): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', Rule::in(CatalogService::SORTS)],
            'currency' => ['nullable', Rule::in(Money::supported())],
        ]);

        return view('products.index', [
            'products' => $catalog->paginate($filters),
            'categories' => $catalog->categories(),
            'filters' => $filters,
        ]);
    }

    public function show(string $slug, CatalogService $catalog, CartService $cart, CurrencyConverter $converter): View
    {
        $product = $catalog->findBySlug($slug);

        // Indicative price in the cart currency, if a rate exists.
        $cartCurrency = $cart->currency();
        $converted = null;
        if ($cartCurrency !== $product->currency && $converter->rate($product->currency, $cartCurrency) !== null) {
            $converted = $converter->convert($product->price_minor, $product->currency, $cartCurrency)[0];
        }

        return view('products.show', [
            'product' => $product,
            'cartCurrency' => $cartCurrency,
            'convertedMinor' => $converted,
        ]);
    }

    /** JSON for the optional live-search script. The page works without it. */
    public function suggest(Request $request, CatalogService $catalog): JsonResponse
    {
        $term = (string) $request->query('q', '');
        $results = $catalog->suggest(mb_substr($term, 0, 100))->map(fn ($p) => [
            'title' => $p->title,
            'url' => route('products.show', $p->slug),
        ]);

        return response()->json(['results' => $results]);
    }
}
