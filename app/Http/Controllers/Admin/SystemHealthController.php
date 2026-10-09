<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;
use Illuminate\View\View;

class SystemHealthController extends Controller
{
    public function __invoke(SystemHealthService $health): View
    {
        $checks = $health->checks();

        return view('admin.health', ['checks' => $checks, 'overall' => $health->overall($checks), 'checkedAt' => now()]);
    }
}
