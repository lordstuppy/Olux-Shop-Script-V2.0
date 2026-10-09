@extends('layouts.app')

@section('title', __('Disputes'))
@section('noindex', true)
@if (auth()->user()->isSeller())
    @section('subnav') @include('partials.seller-nav') @endsection
@endif

@section('content')
    <h1>{{ __('Disputes') }}</h1>
    <p>{{ __('To report a problem with a purchase, open the order and choose "Report a problem with this item".') }} <a href="{{ route('account.orders') }}">{{ __('Your orders') }}</a></p>

    @foreach (['asBuyer' => __('Your purchases'), 'asSeller' => __('Your sales')] as $var => $heading)
        @if ($var === 'asBuyer' || auth()->user()->isSeller())
            <h2>{{ $heading }}</h2>
            @if ($$var->isEmpty())
                <p>{{ __('No disputes.') }}</p>
            @else
                <div class="table-wrap">
                    <table>
                        <thead><tr><th scope="col">#</th><th scope="col">{{ __('Item') }}</th><th scope="col">{{ __('Order') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Opened') }}</th></tr></thead>
                        <tbody>
                            @foreach ($$var as $dispute)
                                <tr>
                                    <td><a href="{{ route('disputes.show', $dispute) }}">{{ $dispute->id }}</a></td>
                                    <td>{{ $dispute->item->title }}</td>
                                    <td class="mono">{{ $dispute->order->shortId() }}</td>
                                    <td><x-status :value="$dispute->status" />@if ($dispute->resolution) {{ $dispute->resolution->label() }}@endif</td>
                                    <td>{{ $dispute->created_at->format('Y-m-d') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    @endforeach
@endsection
