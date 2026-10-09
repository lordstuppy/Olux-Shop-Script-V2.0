@extends('layouts.app')

@section('title', 'Order '.$order->shortId().' status')
@section('noindex', true)

@if ($order->status === \App\Enums\OrderStatus::Pending || $order->status === \App\Enums\OrderStatus::Paid)
    @push('head')
        <meta http-equiv="refresh" content="30">
    @endpush
@endif

@section('content')
    <h1>Order <span class="mono">{{ $order->shortId() }}</span></h1>

    @php [$level, $text] = $message; @endphp
    <div class="flash flash-{{ $level }}" role="{{ $level === 'error' ? 'alert' : 'status' }}">{{ $text }}</div>

    <p>Status: <x-status :value="$order->status" /></p>
    <div class="actions">
        <a class="btn" href="{{ route('orders.show', $order) }}">View order details</a>
        @if ($order->status === \App\Enums\OrderStatus::Pending)
            <a class="btn-secondary btn" href="{{ route('orders.pay', $order) }}">Back to payment instructions</a>
            <a class="btn-secondary btn" href="{{ route('orders.result', $order) }}">Refresh status</a>
        @endif
    </div>
@endsection
