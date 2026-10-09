@extends('layouts.app')

@section('title', __('Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Admin overview') }}</h1>
    <ul class="stats">
        @foreach ($counts as $label => $value)
            <li><span>{{ $label }}</span><strong>{{ $value }}</strong></li>
        @endforeach
    </ul>

    <h2>{{ __('Revenue, last 30 days') }}</h2>
    @if ($revenue->isEmpty())
        <p>{{ __('No paid orders in the last 30 days.') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">{{ __('Currency') }}</th><th scope="col" class="num">{{ __('Orders') }}</th><th scope="col" class="num">{{ __('Gross') }}</th><th scope="col" class="num">{{ __('Refunded') }}</th><th scope="col" class="num">{{ __('Net') }}</th></tr></thead>
                <tbody>
                    @foreach ($revenue as $row)
                        <tr>
                            <td>{{ $row->currency }}</td>
                            <td class="num">{{ $row->orders }}</td>
                            <td class="num">{{ money((int) $row->gross, $row->currency) }}</td>
                            <td class="num">{{ money((int) $row->refunded, $row->currency) }}</td>
                            <td class="num">{{ money((int) $row->gross - (int) $row->refunded, $row->currency) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2>{{ __('Recent orders') }}</h2>
    @include('admin.orders.table', ['orders' => $recentOrders])
@endsection
