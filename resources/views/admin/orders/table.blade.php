<div class="table-wrap">
    <table>
        <thead><tr><th scope="col">{{ __('Order') }}</th><th scope="col">{{ __('Buyer') }}</th><th scope="col">{{ __('Created') }}</th><th scope="col" class="num">{{ __('Total') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
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
                <tr><td colspan="5">{{ __('No orders.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
