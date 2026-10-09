<div class="table-wrap">
    <table>
        <thead><tr><th scope="col">Order</th><th scope="col">Buyer</th><th scope="col">Created</th><th scope="col" class="num">Total</th><th scope="col">Status</th></tr></thead>
        <tbody>
            @forelse ($orders as $order)
                <tr>
                    <td><a class="mono" href="{{ route('admin.orders.show', $order) }}">{{ $order->shortId() }}</a></td>
                    <td><a href="{{ route('admin.users.show', $order->buyer) }}">{{ $order->buyer->email }}</a></td>
                    <td>{{ $order->created_at->format('Y-m-d H:i') }}</td>
                    <td class="num">{{ money($order->total_minor, $order->currency) }}</td>
                    <td><x-status :value="$order->status" /></td>
                </tr>
            @empty
                <tr><td colspan="5">No orders.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
