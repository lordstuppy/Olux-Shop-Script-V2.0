<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Services\AuditLogger;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        ], ['rate.regex' => __('Enter the rate as a positive decimal with up to 10 decimal places, e.g. 0.9215.')]);

        $rate = DB::transaction(function () use ($data, $request) {
            $rate = ExchangeRate::query()->where('base', $data['base'])->where('quote', $data['quote'])->lockForUpdate()->first();
            if ($rate === null) {
                return ExchangeRate::create(['base' => $data['base'], 'quote' => $data['quote'], 'rate' => $data['rate'], 'source' => 'manual',
                    'updated_by' => $request->user()->id, 'reference_rate' => $data['rate'], 'reference_at' => now()]);
            }
            // Bounded against the rate at the start of a 24-hour window, so
            // neither one edit nor a series of small ones can make prices
            // (nearly) free; a real large move takes several days.
            if ($rate->reference_at === null || $rate->reference_at->lt(now()->subDay())) {
                $rate->reference_rate = $rate->rate;
                $rate->reference_at = now();
            }
            $limit = (int) config('shop.exchange_rate_max_change_percent');
            $ratio = BigDecimal::of($data['rate'])->dividedBy((string) $rate->reference_rate, 10, RoundingMode::HalfUp);
            $low = BigDecimal::of(100 - $limit)->dividedBy(100, 10);
            $high = BigDecimal::of(100 + $limit)->dividedBy(100, 10);
            if ($ratio->isLessThan($low) || $ratio->isGreaterThan($high)) {
                $plain = fn (BigDecimal $d) => rtrim(rtrim((string) $d, '0'), '.');
                $reference = BigDecimal::of((string) $rate->reference_rate);
                throw ValidationException::withMessages(['rate' => __('A rate may change by at most :percent% within 24 hours (from :reference). Enter a rate between :low and :high, or change it in steps on later days.', [
                    'percent' => $limit,
                    'reference' => $plain($reference->toScale(10)),
                    'low' => $plain($reference->multipliedBy($low)->toScale(10, RoundingMode::Up)),
                    'high' => $plain($reference->multipliedBy($high)->toScale(10, RoundingMode::Down)),
                ])]);
            }
            $rate->fill(['rate' => $data['rate'], 'source' => 'manual', 'updated_by' => $request->user()->id])->save();

            return $rate;
        });
        $audit->log('exchange_rate.updated', $rate, ['pair' => "{$data['base']}/{$data['quote']}", 'rate' => $data['rate']]);

        return back()->with('success', __('1 :base = :rate :quote saved. New orders use this rate; existing orders keep theirs.', ['base' => $data['base'], 'rate' => $data['rate'], 'quote' => $data['quote']]));
    }
}
