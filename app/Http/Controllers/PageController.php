<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class PageController extends Controller
{
    public const SITEMAP_CACHE_KEY = 'shop.sitemap';

    public function terms(): View
    {
        return view('pages.terms');
    }

    public function privacy(): View
    {
        return view('pages.privacy');
    }

    public function retention(): View
    {
        return view('pages.retention');
    }

    /**
     * Rendered at most once an hour and kept gzip-compressed: rendering 17,000
     * product URLs took 0.9 s and 2 MB per request on a public, unthrottled
     * URL. A product listed or unlisted since then shows up within the hour.
     * The XML holds no secrets, so it is compressed (unlike HTML pages).
     */
    public function sitemap(Request $request): Response
    {
        $gzip = base64_decode(Cache::remember(self::SITEMAP_CACHE_KEY, 3600, function () {
            $products = Product::query()->visible()->select(['slug', 'updated_at'])->orderBy('id')->limit(45000)->get();
            $categories = Category::query()->select(['slug', 'updated_at'])->orderBy('name')->get();

            // Base64: the cache column is text, compressed bytes are not.
            return base64_encode((string) gzencode(view('pages.sitemap', compact('products', 'categories'))->render(), 6));
        }));
        $headers = ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600', 'Vary' => 'Accept-Encoding'];
        if (str_contains((string) $request->header('Accept-Encoding'), 'gzip')) {
            return response($gzip, 200, $headers + ['Content-Encoding' => 'gzip']);
        }

        return response((string) gzdecode($gzip), 200, $headers);
    }

    /** RFC 9116: where to report vulnerabilities (SHOP_SECURITY_EMAIL, see SECURITY.md). */
    public function securityTxt(): Response
    {
        $body = implode("\n", [
            'Contact: mailto:'.config('shop.security_email'),
            'Expires: '.now()->addYear()->startOfDay()->toIso8601ZuluString(),
            'Preferred-Languages: en',
            'Canonical: '.route('security-txt'),
            '',
        ]);

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function robots(): Response
    {
        $body = implode("\n", [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /seller',
            'Disallow: /orders',
            'Disallow: /cart',
            'Disallow: /checkout',
            'Disallow: /wallet',
            'Disallow: /account',
            'Disallow: /tickets',
            'Disallow: /search/',
            'Sitemap: '.route('sitemap'),
            '',
        ]);

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
