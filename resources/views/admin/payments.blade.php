@extends('layouts.app')

@section('title', __('Payments - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Payments') }}</h1>
    <form class="filters" method="get">
        <x-select name="status" :label="__('Status')" :options="collect(\App\Enums\PaymentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" :value="$filters['status'] ?? ''" :placeholder="__('Any status')" />
        <button type="submit">{{ __('Filter') }}</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">#</th><th scope="col">{{ __('Order') }}</th><th scope="col">{{ __('Kind') }}</th><th scope="col">{{ __('Provider') }}</th><th scope="col">{{ __('Reference') }}</th><th scope="col" class="num">{{ __('Amount') }}</th><th scope="col" class="num">{{ __('Received') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Created') }}</th></tr></thead>
            <tbody>
                @forelse ($payments as $payment)
                    <tr>
                        <td>{{ $payment->id }}</td>
                        <td><a class="mono" href="{{ route('admin.orders.show', $payment->order) }}">{{ $payment->order->shortId() }}</a></td>
                        <td>{{ $payment->kind->label() }}</td>
                        <td>{{ $payment->provider->label() }} {{ $payment->crypto }}</td>
                        <td class="mono">{{ $payment->provider_reference }}</td>
                        <td class="num">{{ money($payment->amount_minor, $payment->currency) }}</td>
                        <td class="num">{{ money($payment->received_minor, $payment->currency) }}</td>
                        <td><x-status :value="$payment->status" /> {{ $payment->failure_reason }}</td>
                        <td>{{ $payment->created_at->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9">{{ __('No payments.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $payments->links() }}
@endsection
