@extends('layouts.app')

@section('title', 'Your orders')
@section('noindex', true)

@section('content')
    <h1>Your orders</h1>
    @if ($orders->isEmpty())
        <p>You have not placed any orders yet. <a href="{{ route('products.index') }}">Browse products</a>.</p>
    @else
        <div class="table-wrap">
            <table>
                <caption>Most recent first</caption>
                <thead>
                    <tr>
                        <th scope="col">Order</th>
                        <th scope="col">Placed</th>
                        <th scope="col">Items</th>
                        <th scope="col" class="num">Total</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orders as $order)
                        <tr>
                            <td><a href="{{ route('orders.show', $order) }}" class="mono">{{ $order->shortId() }}</a></td>
                            <td><time datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->format('Y-m-d H:i') }} UTC</time></td>
                            <td>{{ $order->items_count }}</td>
                            <td class="num">{{ money($order->total_minor, $order->currency) }}</td>
                            <td><x-status :value="$order->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $orders->links() }}
    @endif
@endsection
