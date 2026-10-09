@extends('layouts.app')

@section('title', __('Reconciliation - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Payout ledger reconciliation') }}</h1>
    <p>{{ __('Checked :time UTC. The seller ledger is compared with the earnings of completed orders and with payout requests. The same check runs daily', ['time' => $checkedAt->format('Y-m-d H:i:s')]) }} (<span class="mono">php artisan shop:reconcile-payouts</span>).</p>
    @if ($mismatches === [])
        <div class="flash flash-success" role="status">{{ __('No differences: the ledger matches completed orders and payouts.') }}</div>
    @else
        <div class="flash flash-error" role="alert">{{ __(':count differences found. Do not pay out affected sellers until they are explained.', ['count' => count($mismatches)]) }}</div>
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">{{ __('Seller') }}</th><th scope="col">{{ __('Currency') }}</th><th scope="col">{{ __('Check') }}</th><th scope="col" class="num">{{ __('Expected') }}</th><th scope="col" class="num">{{ __('Ledger') }}</th></tr></thead>
                <tbody>
                    @foreach ($mismatches as $m)
                        <tr><td>{{ $m['seller_id'] }}</td><td>{{ $m['currency'] }}</td><td>{{ $m['check'] }}</td><td class="num">{{ money($m['expected'], $m['currency']) }}</td><td class="num">{{ money($m['actual'], $m['currency']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
