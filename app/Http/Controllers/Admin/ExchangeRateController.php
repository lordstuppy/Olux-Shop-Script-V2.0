<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ExchangeRateController extends Controller
{
    public function index(): View
    {
        return view('admin.exchange-rates', ['rates' => ExchangeRate::orderBy('base')->orderBy('quote')->get(), 'currencies' => Money::supported()]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'base' => ['required', Rule::in(Money::supported())],
            'quote' => ['required', Rule::in(Money::supported()), 'different:base'],
            'rate' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,10})?$/', 'not_regex:/^0+(\.0+)?$/'],
        ], ['rate.regex' => 'Enter the rate as a positive decimal with up to 10 decimal places, e.g. 0.9215.']);

        $rate = ExchangeRate::updateOrCreate(
            ['base' => $data['base'], 'quote' => $data['quote']],
            ['rate' => $data['rate'], 'source' => 'manual', 'updated_by' => $request->user()->id],
        );
        $audit->log('exchange_rate.updated', $rate, ['pair' => "{$data['base']}/{$data['quote']}", 'rate' => $data['rate']]);

        return back()->with('success', "1 {$data['base']} = {$data['rate']} {$data['quote']} saved. New orders use this rate; existing orders keep theirs.");
    }
}
