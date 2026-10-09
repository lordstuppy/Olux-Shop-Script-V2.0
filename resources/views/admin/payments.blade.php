@extends('layouts.app')

@section('title', 'Payments - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Payments</h1>
    <form class="filters" method="get">
        <x-select name="status" label="Status" :options="collect(\App\Enums\PaymentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])->all()" :value="$filters['status'] ?? ''" placeholder="Any status" />
        <button type="submit">Filter</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">#</th><th scope="col">Order</th><th scope="col">Kind</th><th scope="col">Provider</th><th scope="col">Reference</th><th scope="col" class="num">Amount</th><th scope="col" class="num">Received</th><th scope="col">Status</th><th scope="col">Created</th></tr></thead>
            <tbody>
                @forelse ($payments as $payment)
                    <tr>
                        <td>{{ $payment->id }}</td>
                        <td><a class="mono" href="{{ route('admin.orders.show', $payment->order) }}">{{ $payment->order->shortId() }}</a></td>
                        <td>{{ $payment->kind->value }}</td>
                        <td>{{ $payment->provider->value }} {{ $payment->crypto }}</td>
                        <td class="mono">{{ $payment->provider_reference }}</td>
                        <td class="num">{{ money($payment->amount_minor, $payment->currency) }}</td>
                        <td class="num">{{ money($payment->received_minor, $payment->currency) }}</td>
                        <td><x-status :value="$payment->status" /> {{ $payment->failure_reason }}</td>
                        <td>{{ $payment->created_at->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9">No payments.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $payments->links() }}
@endsection
