@extends('layouts.app')

@section('title', 'Open a ticket')
@section('noindex', true)

@section('content')
    <h1>Open a support ticket</h1>
    <form method="post" action="{{ route('tickets.store') }}" class="stack">
        @csrf
        @if ($order)
            <input type="hidden" name="order" value="{{ $order->public_id }}">
            <p>About order <a href="{{ route('orders.show', $order) }}" class="mono">{{ $order->shortId() }}</a>.</p>
            <input type="hidden" name="category" value="order_issue">
        @else
            <x-select name="category" label="Topic" :options="['support' => 'General question', 'billing' => 'Billing and balance', 'seller' => 'Selling on the shop', 'order_issue' => 'Problem with an order']" value="support" />
        @endif
        <x-field name="subject" label="Subject" maxlength="160" required />
        <x-textarea name="body" label="Message" maxlength="5000" hint="Describe the problem. Never include passwords or private keys." required />
        <button type="submit">Open ticket</button>
    </form>
@endsection
