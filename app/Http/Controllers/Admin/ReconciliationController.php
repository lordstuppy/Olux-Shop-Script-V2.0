<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PayoutService;
use Illuminate\View\View;

class ReconciliationController extends Controller
{
    public function __invoke(PayoutService $payouts): View
    {
        return view('admin.reconciliation', ['mismatches' => $payouts->reconcile(), 'checkedAt' => now()]);
    }
}
