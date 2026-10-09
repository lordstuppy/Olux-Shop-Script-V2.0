@extends('layouts.app')

@section('title', __('Disputes - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Disputes') }}</h1>
    <form class="filters" method="get">
        <x-select name="status" :label="__('Status')" :options="['open' => __('Open (:count)', ['count' => (int) ($counts['awaiting_seller'] ?? 0) + (int) ($counts['awaiting_staff'] ?? 0)])] + collect(\App\Enums\DisputeStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label().' ('.(int) ($counts[$s->value] ?? 0).')'])->all()" :value="$status" />
        <button type="submit" class="btn-secondary">{{ __('Filter') }}</button>
    </form>

    @if ($disputes->isEmpty())
        <p>{{ __('No disputes in this view.') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">#</th><th scope="col">{{ __('Item') }}</th><th scope="col">{{ __('Buyer') }}</th><th scope="col">{{ __('Seller') }}</th><th scope="col">{{ __('Reason') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Seller deadline') }}</th></tr></thead>
                <tbody>
                    @foreach ($disputes as $dispute)
                        <tr>
                            <td><a href="{{ route('disputes.show', $dispute) }}">{{ $dispute->id }}</a></td>
                            <td>{{ $dispute->item->title }}<br><span class="muted mono">{{ $dispute->order->shortId() }}</span></td>
                            <td>{{ $dispute->buyer->email }}</td>
                            <td>{{ $dispute->seller->sellerProfile?->display_name ?? $dispute->seller->email }}</td>
                            <td>{{ $dispute->reason->label() }}</td>
                            <td><x-status :value="$dispute->status" />@if ($dispute->escalated_at) <span class="error-text">{{ __('escalated') }}</span>@endif</td>
                            <td>{{ $dispute->seller_respond_by->format('Y-m-d H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $disputes->links() }}
    @endif
@endsection
