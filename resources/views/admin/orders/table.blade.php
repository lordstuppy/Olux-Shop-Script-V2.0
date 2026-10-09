<div class="table-wrap">
    <table>
        @php $selectable ??= false; @endphp
        <thead><tr>@if ($selectable)<th scope="col"><span class="visually-hidden">{{ __('Select') }}</span></th>@endif<th scope="col">{{ __('Order') }}</th><th scope="col">{{ __('Buyer') }}</th><th scope="col">{{ __('Created') }}</th><th scope="col" class="num">{{ __('Total') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
        <tbody>
            @forelse ($orders as $order)
                <tr>
                    @if ($selectable)<td><input type="checkbox" name="ids[]" value="{{ $order->public_id }}" form="bulk-orders" aria-label="{{ __('Select order :order', ['order' => $order->shortId()]) }}"></td>@endif
                    <td><a class="mono" href="{{ route('admin.orders.show', $order) }}">{{ $order->shortId() }}</a></td>
                    <td><a href="{{ route('admin.users.show', $order->buyer) }}">{{ $order->buyer->email }}</a></td>
                    <td>{{ $order->created_at->format('Y-m-d H:i') }}</td>
                    <td class="num">{{ money($order->total_minor, $order->currency) }}</td>
                    <td><x-status :value="$order->status" /></td>
                </tr>
            @empty
                <tr><td colspan="{{ $selectable ? 6 : 5 }}">{{ __('No orders.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
