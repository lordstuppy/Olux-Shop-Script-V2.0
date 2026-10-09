@extends('layouts.app')

@section('title', __('Payouts - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Payout requests') }}</h1>
    <p><a href="{{ route('admin.reconciliation') }}">{{ __('Run ledger reconciliation') }}</a> {{ __('before paying out.') }}</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">#</th><th scope="col">{{ __('Seller') }}</th><th scope="col" class="num">{{ __('Amount') }}</th><th scope="col">{{ __('Destination') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Actions') }}</th></tr></thead>
            <tbody>
                @forelse ($payouts as $payout)
                    <tr>
                        <td>{{ $payout->id }}</td>
                        <td><a href="{{ route('admin.users.show', $payout->seller) }}">{{ $payout->seller->email }}</a></td>
                        <td class="num">{{ money($payout->amount_minor, $payout->currency) }}</td>
                        <td class="mono">{{ $payout->destination }}<br>{{ $payout->seller->sellerProfile?->payout_crypto }}</td>
                        <td>
                            <x-status :value="$payout->status" /> <span class="mono">{{ $payout->reference }}</span> {{ $payout->note }}
                            @if ($payout->crypto_amount)<br>{{ $payout->crypto_amount }} {{ $payout->crypto }} {{ __('via Shkeeper') }} @endif
                            @if ($payout->failure_reason)<br><span class="error-text">{{ $payout->failure_reason }}</span>@endif
                        </td>
                        <td>
                            @if ($payout->status === \App\Enums\PayoutStatus::Requested)
                                <form method="post" action="{{ route('admin.payouts.approve', $payout) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="btn-secondary">{{ __('Approve #:id', ['id' => $payout->id]) }}</button>
                                </form>
                            @endif
                            @if ($shkeeperEnabled && in_array($payout->status, [\App\Enums\PayoutStatus::Approved, \App\Enums\PayoutStatus::Failed], true))
                                <form method="post" action="{{ route('admin.payouts.send', $payout) }}" class="mt" data-once>
                                    @csrf
                                    <button type="submit">{{ __('Send #:id via Shkeeper', ['id' => $payout->id]) }}</button>
                                </form>
                            @endif
                            @if (in_array($payout->status, [\App\Enums\PayoutStatus::Requested, \App\Enums\PayoutStatus::Approved, \App\Enums\PayoutStatus::Failed], true))
                                <form method="post" action="{{ route('admin.payouts.paid', $payout) }}" class="actions mt">
                                    @csrf
                                    <x-field name="reference" :id="'ref-'.$payout->id" :label="__('Transaction reference')" maxlength="255" required />
                                    <button type="submit">{{ __('Mark #:id paid', ['id' => $payout->id]) }}</button>
                                </form>
                                <form method="post" action="{{ route('admin.payouts.reject', $payout) }}" class="actions mt">
                                    @csrf
                                    <x-field name="note" :id="'note-'.$payout->id" :label="__('Rejection reason')" maxlength="500" required />
                                    <button type="submit" class="btn-danger">{{ __('Reject #:id', ['id' => $payout->id]) }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">{{ __('No payout requests.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $payouts->links() }}
@endsection
