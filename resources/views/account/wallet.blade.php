@extends('layouts.app')

@section('title', __('Wallet'))
@section('noindex', true)

@section('content')
    <h1>{{ __('Wallet') }}</h1>
    <p>{{ __('Balance:') }} <strong>{{ money($user->balance_minor, $user->currency) }}</strong></p>
    <p class="hint">{{ __('Your balance holds one currency. Credits in another currency can only be added while the balance is zero. Use the balance at checkout when the order is in :currency.', ['currency' => $user->currency]) }}</p>

    <h2>{{ __('Redeem a gift card') }}</h2>
    <form method="post" action="{{ route('wallet.redeem') }}" class="stack">
        @csrf
        <x-field name="code" :label="__('Gift card code')" :hint="__('Format XXXX-XXXX-XXXX-XXXX')" autocomplete="off" maxlength="40" required />
        <button type="submit">{{ __('Redeem') }}</button>
    </form>

    <h2>{{ __('History') }}</h2>
    @if ($transactions->isEmpty())
        <p>{{ __('No balance activity yet.') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col">{{ __('Note') }}</th><th scope="col" class="num">{{ __('Amount') }}</th><th scope="col" class="num">{{ __('Balance after') }}</th></tr>
                </thead>
                <tbody>
                    @foreach ($transactions as $tx)
                        <tr>
                            <td>{{ $tx->created_at->format('Y-m-d H:i') }}</td>
                            <td>{{ \App\Support\Labels::balanceType($tx->type) }}</td>
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
