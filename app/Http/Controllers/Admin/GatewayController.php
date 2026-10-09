<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ShkeeperException;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\PaymentService;
use App\Services\Shkeeper\ShkeeperClient;
use App\Services\ShkeeperPayoutService;
use App\Support\GatewayLog;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Payment gateway page: payment methods, coins and order limits (super
 * admin), the connection settings that come from the environment (read
 * only; secrets are never shown), 24-hour health counts and the gateway
 * log (callbacks and API calls) without database access.
 */
class GatewayController extends Controller
{
    public function index(Request $request, PaymentService $payments): View
    {
        $filters = $request->validate([
            'channel' => ['nullable', Rule::in(GatewayLog::CHANNELS)],
            'outcome' => ['nullable', Rule::in(GatewayLog::OUTCOMES)],
        ]);
        $logs = DB::table('gateway_logs')->orderByDesc('id')
            ->when($filters['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->when($filters['outcome'] ?? null, fn ($q, $o) => $q->where('outcome', $o))
            ->paginate(50)->withQueryString();

        $since = now()->subDay();
        $counts = DB::table('gateway_logs')->where('created_at', '>=', $since)
            ->groupBy('channel', 'outcome')->selectRaw('channel, outcome, COUNT(*) AS c')->get()
            ->groupBy('channel')->map(fn ($rows) => $rows->pluck('c', 'outcome'));

        $base = (string) config('shop.default_currency');

        return view('admin.gateway', [
            'logs' => $logs,
            'filters' => $filters,
            'counts' => $counts,
            'lastWebhook' => DB::table('gateway_logs')->where('channel', 'webhook')->where('outcome', 'ok')->max('created_at'),
            'lastApiOk' => DB::table('gateway_logs')->where('channel', 'api')->where('outcome', 'ok')->max('created_at'),
            'cryptos' => $this->knownCryptos($payments),
            'disabled' => array_filter(array_map('trim', explode(',', strtoupper((string) config('shop.crypto_disabled'))))),
            'base' => $base,
            'config' => [
                'base_url' => (string) config('services.shkeeper.base_url'),
                'api_key' => filled(config('services.shkeeper.api_key')),
                'webhook_secret' => filled(config('services.shkeeper.webhook_secret')),
                'callback_url' => config('services.shkeeper.callback_url') ?: route('webhooks.shkeeper'),
                'payout_callback_url' => config('services.shkeeper.payout_callback_url') ?: route('webhooks.shkeeper.payouts'),
                'tolerance' => (int) config('services.shkeeper.webhook_tolerance'),
                'allowed_ips' => (string) config('shop.webhook_allowed_ips'),
                'payouts' => ShkeeperPayoutService::enabled(),
            ],
            'min' => Money::toDecimal((int) config('shop.order_min_minor'), $base),
            'max' => Money::toDecimal((int) config('shop.order_max_minor'), $base),
        ]);
    }

    public function update(Request $request, PaymentService $payments, AuditLogger $audit): RedirectResponse
    {
        $known = array_column($this->knownCryptos($payments), 'name');
        $data = $request->validate([
            'payments_crypto_enabled' => ['nullable', 'boolean'],
            'payments_balance_enabled' => ['nullable', 'boolean'],
            'crypto_enabled' => ['nullable', 'array'],
            'crypto_enabled.*' => ['string', Rule::in($known)],
            'order_min' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'order_max' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
        ]);
        $base = (string) config('shop.default_currency');
        $values = [
            'payments_crypto_enabled' => (bool) ($data['payments_crypto_enabled'] ?? false),
            'payments_balance_enabled' => (bool) ($data['payments_balance_enabled'] ?? false),
            'crypto_disabled' => implode(',', array_values(array_diff($known, $data['crypto_enabled'] ?? []))),
            'order_min_minor' => Money::parseInput((string) ($data['order_min'] ?? '0') ?: '0', $base),
            'order_max_minor' => Money::parseInput((string) ($data['order_max'] ?? '0') ?: '0', $base),
        ];
        if ($values['order_max_minor'] > 0 && $values['order_max_minor'] < $values['order_min_minor']) {
            return back()->withInput()->withErrors(['order_max' => __('The maximum order must be at least the minimum order (or 0 for no maximum).')]);
        }
        if ($values['payments_crypto_enabled'] && $known !== [] && count($data['crypto_enabled'] ?? []) === 0) {
            return back()->withInput()->withErrors(['crypto_enabled' => __('Keep at least one cryptocurrency enabled, or switch crypto payments off.')]);
        }

        $changed = collect($values)->filter(fn ($v, $k) => (string) (is_bool($v) ? (int) $v : $v) !== (string) (is_bool(config('shop.'.$k)) ? (int) config('shop.'.$k) : config('shop.'.$k)))->all();
        Settings::save($values, $request->user());
        if ($changed !== []) {
            $audit->log('gateway.settings_updated', null, $changed);
        }

        return back()->with('success', $changed === [] ? __('No settings changed.') : trans_choice('{1} Saved :count changed setting.|[0,*] Saved :count changed settings.', count($changed)));
    }

    /** Calls Shkeeper once, uncached; the result also lands in the gateway log. */
    public function check(ShkeeperClient $client): RedirectResponse
    {
        try {
            $list = $client->availableCryptos();
            Cache::forget('shkeeper.cryptos');

            return back()->with('success', __('Shkeeper answered and offers :list.', ['list' => implode(', ', array_column($list, 'name')) ?: __('no coins')]));
        } catch (ShkeeperException $e) {
            return back()->with('error', __('Shkeeper did not answer correctly: :reason', ['reason' => $e->getMessage()]));
        }
    }

    /** @return list<array{name: string, display_name: string}> */
    private function knownCryptos(PaymentService $payments): array
    {
        return $payments->availableCryptos();
    }
}
