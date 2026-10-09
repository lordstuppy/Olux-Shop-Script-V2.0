@extends('layouts.app')

@section('title', __('Seller dashboard'))
@section('noindex', true)
@section('subnav') @include('partials.seller-nav') @endsection

@section('content')
    <h1>{{ __('Seller dashboard') }}</h1>

    <h2>{{ __('Earnings') }}</h2>
    @if ($balances === [])
        <p>{{ __('No sales yet.') }}</p>
    @else
        <ul class="stats">
            @foreach ($balances as $currency => $b)
                <li><span>{{ __('Available (:currency)', ['currency' => $currency]) }}</span><strong>{{ money($b['available'], $currency) }}</strong><span class="muted">{{ __(':amount on hold', ['amount' => money($b['pending'], $currency)]) }}</span></li>
            @endforeach
        </ul>
    @endif

    <h2>{{ __('Products') }}</h2>
    <ul class="stats">
        @foreach (\App\Enums\ProductStatus::cases() as $status)
            <li><span>{{ $status->label() }}</span><strong>{{ (int) ($productCounts[$status->value] ?? 0) }}</strong></li>
        @endforeach
    </ul>
    <p><a class="btn" href="{{ route('seller.products.create') }}">{{ __('Add a product') }}</a></p>

    <h2>{{ __('Waiting for your delivery') }}</h2>
    @if ($awaitingDelivery->isEmpty())
        <p>{{ __('Nothing to deliver.') }}</p>
    @else
        @foreach ($awaitingDelivery as $item)
            <section class="card mt" aria-labelledby="deliver-{{ $item->id }}">
                <h3 id="deliver-{{ $item->id }}">{{ $item->title }} x {{ $item->quantity }} ({{ __('order') }} <span class="mono">{{ $item->order->shortId() }}</span>)</h3>
                <form method="post" action="{{ route('seller.items.deliver', $item) }}" class="stack">
                    @csrf
                    <x-textarea name="payload" :id="'payload-'.$item->id" :label="__('Delivery details for the buyer')" :hint="__('Licence keys, access instructions or a download location. Stored encrypted.')" maxlength="10000" required />
                    <button type="submit">{{ __('Deliver') }}</button>
                </form>
            </section>
        @endforeach
    @endif

    <h2>{{ __('Recent sales') }}</h2>
    @if ($recentSales->isEmpty())
        <p>{{ __('No sales yet.') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">{{ __('Order') }}</th><th scope="col">{{ __('Product') }}</th><th scope="col" class="num">{{ __('Qty') }}</th><th scope="col" class="num">{{ __('Your earning') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
                <tbody>
                    @foreach ($recentSales as $item)
                        <tr>
                            <td class="mono">{{ $item->order->shortId() }}</td>
                            <td>{{ $item->title }}</td>
                            <td class="num">{{ $item->quantity }}</td>
                            <td class="num">{{ money($item->seller_earning_minor, $item->order->currency) }}</td>
                            <td><x-status :value="$item->order->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
