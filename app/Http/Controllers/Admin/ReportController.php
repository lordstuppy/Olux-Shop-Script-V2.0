<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function __invoke(Request $request, ReportService $reports): View
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : $to->copy()->subDays(29)->startOfDay();
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366)->startOfDay();
        }

        return view('admin.reports', [
            'from' => $from,
            'to' => $to,
            'daily' => $reports->dailyRevenue($from, $to),
            'totals' => $reports->totals($from, $to),
            'conversion' => $reports->conversion($from, $to),
            'products' => $reports->topProducts($from, $to),
            'sellers' => $reports->topSellers($from, $to),
        ]);
    }
}
