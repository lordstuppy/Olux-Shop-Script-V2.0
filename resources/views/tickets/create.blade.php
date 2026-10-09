@extends('layouts.app')

@section('title', __('Open a ticket'))
@section('noindex', true)

@section('content')
    <h1>{{ __('Open a support ticket') }}</h1>
    <p class="hint">{{ __('A key that does not work or an item that never arrived? Open a dispute from the order page instead: the seller must answer and our team decides on a refund or replacement.') }} <a href="{{ route('disputes.index') }}">{{ __('Disputes') }}</a></p>
    <form method="post" action="{{ route('tickets.store') }}" class="stack">
        @csrf
        @if ($order)
            <input type="hidden" name="order" value="{{ $order->public_id }}">
            <p>{{ __('About order') }} <a href="{{ route('orders.show', $order) }}" class="mono">{{ $order->shortId() }}</a>.</p>
            <input type="hidden" name="category" value="order_issue">
        @else
            <x-select name="category" :label="__('Topic')" :options="['support' => __('General question'), 'billing' => __('Billing and balance'), 'seller' => __('Selling on the shop'), 'order_issue' => __('Problem with an order')]" value="support" />
        @endif
        <x-field name="subject" :label="__('Subject')" maxlength="160" required />
        <x-textarea name="body" :label="__('Message')" maxlength="5000" :hint="__('Describe the problem. Never include passwords or private keys.')" required />
        <button type="submit">{{ __('Open ticket') }}</button>
    </form>
@endsection
