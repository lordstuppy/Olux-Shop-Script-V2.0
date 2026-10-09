<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PageController extends Controller
{
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

    public function sitemap(): Response
    {
        $products = Product::query()->visible()->select(['slug', 'updated_at'])->orderBy('id')->limit(45000)->get();
        $categories = Category::query()->select(['slug', 'updated_at'])->orderBy('name')->get();

        return response()->view('pages.sitemap', compact('products', 'categories'))
            ->header('Content-Type', 'application/xml; charset=UTF-8');
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
