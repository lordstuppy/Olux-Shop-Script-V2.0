@extends('layouts.app')

@section('title', 'Payouts - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Payout requests</h1>
    <p><a href="{{ route('admin.reconciliation') }}">Run ledger reconciliation</a> before paying out.</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">#</th><th scope="col">Seller</th><th scope="col" class="num">Amount</th><th scope="col">Destination</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
            <tbody>
                @forelse ($payouts as $payout)
                    <tr>
                        <td>{{ $payout->id }}</td>
                        <td><a href="{{ route('admin.users.show', $payout->seller) }}">{{ $payout->seller->email }}</a></td>
                        <td class="num">{{ money($payout->amount_minor, $payout->currency) }}</td>
                        <td class="mono">{{ $payout->destination }}<br>{{ $payout->seller->sellerProfile?->payout_crypto }}</td>
                        <td>
                            <x-status :value="$payout->status" /> <span class="mono">{{ $payout->reference }}</span> {{ $payout->note }}
                            @if ($payout->crypto_amount)<br>{{ $payout->crypto_amount }} {{ $payout->crypto }} via Shkeeper @endif
                            @if ($payout->failure_reason)<br><span class="error-text">{{ $payout->failure_reason }}</span>@endif
                        </td>
                        <td>
                            @if ($payout->status === \App\Enums\PayoutStatus::Requested)
                                <form method="post" action="{{ route('admin.payouts.approve', $payout) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="btn-secondary">Approve #{{ $payout->id }}</button>
                                </form>
                            @endif
                            @if ($shkeeperEnabled && in_array($payout->status, [\App\Enums\PayoutStatus::Approved, \App\Enums\PayoutStatus::Failed], true))
                                <form method="post" action="{{ route('admin.payouts.send', $payout) }}" class="mt" data-once>
                                    @csrf
                                    <button type="submit">Send #{{ $payout->id }} via Shkeeper</button>
                                </form>
                            @endif
                            @if (in_array($payout->status, [\App\Enums\PayoutStatus::Requested, \App\Enums\PayoutStatus::Approved, \App\Enums\PayoutStatus::Failed], true))
                                <form method="post" action="{{ route('admin.payouts.paid', $payout) }}" class="actions mt">
                                    @csrf
                                    <x-field name="reference" :id="'ref-'.$payout->id" label="Transaction reference" maxlength="255" required />
                                    <button type="submit">Mark #{{ $payout->id }} paid</button>
                                </form>
                                <form method="post" action="{{ route('admin.payouts.reject', $payout) }}" class="actions mt">
                                    @csrf
                                    <x-field name="note" :id="'note-'.$payout->id" label="Rejection reason" maxlength="500" required />
                                    <button type="submit" class="btn-danger">Reject #{{ $payout->id }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">No payout requests.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $payouts->links() }}
@endsection
