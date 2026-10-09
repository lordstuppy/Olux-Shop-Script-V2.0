@extends('layouts.app')

@section('title', __('Order :order status', ['order' => $order->shortId()]))
@section('noindex', true)

@if ($order->status === \App\Enums\OrderStatus::Pending || $order->status === \App\Enums\OrderStatus::Paid)
    @push('head')
        <meta http-equiv="refresh" content="30">
    @endpush
@endif

@section('content')
    <h1>{{ __('Order') }} <span class="mono">{{ $order->shortId() }}</span></h1>

    @php [$level, $text] = $message; @endphp
    <div class="flash flash-{{ $level }}" role="{{ $level === 'error' ? 'alert' : 'status' }}">{{ $text }}</div>

    <p>{{ __('Status:') }} <x-status :value="$order->status" /></p>
    <div class="actions">
        <a class="btn" href="{{ route('orders.show', $order) }}">{{ __('View order details') }}</a>
        @if ($order->status === \App\Enums\OrderStatus::Pending)
            <a class="btn-secondary btn" href="{{ route('orders.pay', $order) }}">{{ __('Back to payment instructions') }}</a>
            <a class="btn-secondary btn" href="{{ route('orders.result', $order) }}">{{ __('Refresh status') }}</a>
        @endif
    </div>
@endsection
