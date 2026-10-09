@extends('layouts.app')

@section('title', 'Sales')
@section('noindex', true)
@section('subnav') @include('partials.seller-nav') @endsection

@section('content')
    <h1>Sales</h1>
    @if ($items->isEmpty())
        <p>No sales yet.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">Date</th><th scope="col">Order</th><th scope="col">Product</th><th scope="col" class="num">Qty</th><th scope="col" class="num">Charged</th><th scope="col" class="num">Refunded</th><th scope="col" class="num">Your earning</th><th scope="col">Delivery</th></tr></thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td>{{ $item->order->paid_at?->format('Y-m-d') }}</td>
                            <td class="mono">{{ $item->order->shortId() }}</td>
                            <td>{{ $item->title }}</td>
                            <td class="num">{{ $item->quantity }}</td>
                            <td class="num">{{ money($item->netMinor(), $item->order->currency) }}</td>
                            <td class="num">{{ money($item->refunded_minor, $item->order->currency) }}</td>
                            <td class="num">{{ money($item->seller_earning_minor, $item->order->currency) }}</td>
                            <td>{{ $item->isDelivered() ? 'Delivered' : 'Pending' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $items->links() }}
    @endif
@endsection
