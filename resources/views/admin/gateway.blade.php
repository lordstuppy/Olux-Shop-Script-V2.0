@extends('layouts.app')

@section('title', __('Payment gateway - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Payment gateway') }}</h1>

    <ul class="stats" aria-label="{{ __('Last 24 hours') }}">
        <li><span>{{ __('Callbacks accepted (24 h)') }}</span><strong>{{ (int) ($counts['webhook']['ok'] ?? 0) + (int) ($counts['payout_webhook']['ok'] ?? 0) }}</strong></li>
        <li><span>{{ __('Rejected signatures (24 h)') }}</span><strong>{{ (int) ($counts['webhook']['rejected_signature'] ?? 0) + (int) ($counts['payout_webhook']['rejected_signature'] ?? 0) }}</strong></li>
        <li><span>{{ __('API errors (24 h)') }}</span><strong>{{ (int) ($counts['api']['error'] ?? 0) }}</strong></li>
        <li><span>{{ __('API timeouts (24 h)') }}</span><strong>{{ (int) ($counts['api']['timeout'] ?? 0) }}</strong></li>
        <li><span>{{ __('Last accepted payment callback') }}</span><strong>{{ $lastWebhook ? \Illuminate\Support\Carbon::parse($lastWebhook)->format('Y-m-d H:i').' UTC' : __('never') }}</strong></li>
        <li><span>{{ __('Last successful API call') }}</span><strong>{{ $lastApiOk ? \Illuminate\Support\Carbon::parse($lastApiOk)->format('Y-m-d H:i').' UTC' : __('never') }}</strong></li>
    </ul>

    <div class="two-col mt">
        <section class="card" aria-labelledby="methods-heading">
            <h2 id="methods-heading">{{ __('Payment methods and limits') }}</h2>
            @can('gateway.manage')
                <form method="post" action="{{ route('admin.gateway.update') }}" class="stack">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="payments_crypto_enabled" value="0">
                    <label class="check"><input type="checkbox" name="payments_crypto_enabled" value="1" @checked(config('shop.payments_crypto_enabled'))> {{ __('Accept cryptocurrency payments (Shkeeper)') }}</label>
                    <fieldset>
                        <legend>{{ __('Accepted cryptocurrencies') }}</legend>
                        @forelse ($cryptos as $crypto)
                            <label class="check"><input type="checkbox" name="crypto_enabled[]" value="{{ $crypto['name'] }}" @checked(! in_array(strtoupper($crypto['name']), $disabled, true))> {{ $crypto['display_name'] }} ({{ $crypto['name'] }})</label>
                        @empty
                            <p class="hint">{{ __('Shkeeper did not report any coins.') }}</p>
                        @endforelse
                    </fieldset>
                    <input type="hidden" name="payments_balance_enabled" value="0">
                    <label class="check"><input type="checkbox" name="payments_balance_enabled" value="1" @checked(config('shop.payments_balance_enabled'))> {{ __('Accept payment from the shop balance') }}</label>
                    <x-field name="order_min" :label="__('Minimum order (:currency)', ['currency' => $base])" :value="$min" inputmode="decimal" maxlength="12" :hint="__('0 means no minimum. Orders in other currencies are converted with the exchange rate.')" />
                    <x-field name="order_max" :label="__('Maximum order (:currency)', ['currency' => $base])" :value="$max" inputmode="decimal" maxlength="12" :hint="__('0 means no maximum.')" />
                    <button type="submit">{{ __('Save gateway settings') }}</button>
                </form>
            @else
                <p>{{ __('Crypto payments:') }} <strong>{{ config('shop.payments_crypto_enabled') ? __('on') : __('off') }}</strong>
                    &middot; {{ __('Balance payments:') }} <strong>{{ config('shop.payments_balance_enabled') ? __('on') : __('off') }}</strong></p>
                <p>{{ __('Order limits: :min to :max', ['min' => $min.' '.$base, 'max' => (int) config('shop.order_max_minor') === 0 ? __('no maximum') : $max.' '.$base]) }}</p>
                <p class="hint">{{ __('Only a super admin can change these settings.') }}</p>
            @endcan
        </section>

        <section class="card" aria-labelledby="connection-heading">
            <h2 id="connection-heading">{{ __('Connection') }}</h2>
            <p class="hint">{{ __('These come from the server environment (.env). Secrets are never shown here.') }}</p>
            <dl class="facts">
                <dt>{{ __('Shkeeper URL') }}</dt><dd class="mono">{{ $config['base_url'] ?: __('not set') }}</dd>
                <dt>{{ __('API key') }}</dt><dd>{{ $config['api_key'] ? __('configured') : __('missing') }}</dd>
                <dt>{{ __('Webhook secret') }}</dt><dd>{{ $config['webhook_secret'] ? __('configured') : __('missing') }}</dd>
                <dt>{{ __('Payment callback URL') }}</dt><dd class="mono">{{ $config['callback_url'] }}</dd>
                <dt>{{ __('Payout callback URL') }}</dt><dd class="mono">{{ $config['payout_callback_url'] }}</dd>
                <dt>{{ __('Signature time window') }}</dt><dd>{{ __(':seconds seconds', ['seconds' => $config['tolerance']]) }}</dd>
                <dt>{{ __('Callback source addresses') }}</dt><dd class="mono">{{ $config['allowed_ips'] ?: __('any') }}</dd>
                <dt>{{ __('Automatic payouts and crypto refunds') }}</dt><dd>{{ $config['payouts'] ? __('on') : __('off') }}</dd>
            </dl>
            <form method="post" action="{{ route('admin.gateway.check') }}">
                @csrf
                <button type="submit" class="btn-secondary">{{ __('Check connection now') }}</button>
            </form>
        </section>
    </div>

    <h2 class="mt">{{ __('Gateway log') }}</h2>
    <form class="filters" method="get">
        <x-select name="channel" :label="__('Channel')" :options="['webhook' => __('Payment callbacks'), 'payout_webhook' => __('Payout callbacks'), 'api' => __('API calls')]" :value="$filters['channel'] ?? ''" :placeholder="__('All channels')" />
        <x-select name="outcome" :label="__('Outcome')" :options="['ok' => __('OK'), 'duplicate' => __('Duplicate'), 'rejected_signature' => __('Rejected signature'), 'rejected_ip' => __('Rejected address'), 'bad_request' => __('Bad request'), 'error' => __('Error'), 'timeout' => __('Timeout')]" :value="$filters['outcome'] ?? ''" :placeholder="__('All outcomes')" />
        <button type="submit" class="btn-secondary">{{ __('Filter') }}</button>
    </form>
    @if ($logs->isEmpty())
        <p>{{ __('Nothing logged yet.') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">{{ __('Time (UTC)') }}</th><th scope="col">{{ __('Channel') }}</th><th scope="col">{{ __('Outcome') }}</th><th scope="col">{{ __('Details') }}</th><th scope="col" class="num">{{ __('HTTP') }}</th><th scope="col" class="num">{{ __('ms') }}</th><th scope="col">{{ __('Source / request') }}</th></tr></thead>
                <tbody>
                    @foreach ($logs as $log)
                        <tr>
                            <td>{{ \Illuminate\Support\Carbon::parse($log->created_at)->format('Y-m-d H:i:s') }}</td>
                            <td>{{ $log->channel }}</td>
                            <td><span class="status status-{{ $log->outcome === 'ok' ? 'active' : ($log->outcome === 'duplicate' ? 'pending' : 'failed') }}">{{ str_replace('_', ' ', $log->outcome) }}</span></td>
                            <td>{{ $log->action }} {{ $log->external_id }} {{ $log->message }}</td>
                            <td class="num">{{ $log->http_status }}</td>
                            <td class="num">{{ $log->duration_ms }}</td>
                            <td class="mono">{{ $log->ip }}<br>{{ $log->request_id }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $logs->links() }}
    @endif
@endsection
