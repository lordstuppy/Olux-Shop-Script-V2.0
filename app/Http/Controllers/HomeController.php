<?php

namespace App\Http\Controllers;

use App\Services\CatalogService;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(CatalogService $catalog): View
    {
        return view('home', [
            'latest' => $catalog->latest(6),
            'categories' => $catalog->categories(),
        ]);
    }
}
