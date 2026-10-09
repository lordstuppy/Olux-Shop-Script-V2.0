@extends('layouts.app')

@section('title', 'Wallet')
@section('noindex', true)

@section('content')
    <h1>Wallet</h1>
    <p>Balance: <strong>{{ money($user->balance_minor, $user->currency) }}</strong></p>
    <p class="hint">Your balance holds one currency. Credits in another currency can only be added while the balance is zero. Use the balance at checkout when the order is in {{ $user->currency }}.</p>

    <h2>Redeem a gift card</h2>
    <form method="post" action="{{ route('wallet.redeem') }}" class="stack">
        @csrf
        <x-field name="code" label="Gift card code" hint="Format XXXX-XXXX-XXXX-XXXX" autocomplete="off" maxlength="40" required />
        <button type="submit">Redeem</button>
    </form>

    <h2>History</h2>
    @if ($transactions->isEmpty())
        <p>No balance activity yet.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th scope="col">Date</th><th scope="col">Type</th><th scope="col">Note</th><th scope="col" class="num">Amount</th><th scope="col" class="num">Balance after</th></tr>
                </thead>
                <tbody>
                    @foreach ($transactions as $tx)
                        <tr>
                            <td>{{ $tx->created_at->format('Y-m-d H:i') }}</td>
                            <td>{{ str_replace('_', ' ', $tx->type) }}</td>
                            <td>{{ $tx->note }}</td>
                            <td class="num">{{ $tx->amount_minor > 0 ? '+' : '-' }}{{ money(abs($tx->amount_minor), $tx->currency) }}</td>
                            <td class="num">{{ money($tx->balance_after_minor, $tx->currency) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $transactions->links() }}
    @endif
@endsection
