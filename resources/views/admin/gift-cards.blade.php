@extends('layouts.app')

@section('title', 'Gift cards - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Gift cards</h1>
    <p>Codes are stored only as a keyed hash. The full code is shown once, right after creation.</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">#</th><th scope="col">Code ends</th><th scope="col" class="num">Amount</th><th scope="col">Created</th><th scope="col">Expires</th><th scope="col">Redeemed</th></tr></thead>
            <tbody>
                @forelse ($cards as $card)
                    <tr>
                        <td>{{ $card->id }}</td>
                        <td class="mono">...{{ $card->code_last4 }}</td>
                        <td class="num">{{ money($card->amount_minor, $card->currency) }}</td>
                        <td>{{ $card->created_at->format('Y-m-d') }}</td>
                        <td>{{ $card->expires_at?->format('Y-m-d') ?? 'Never' }}</td>
                        <td>{{ $card->redeemed_at ? $card->redeemed_at->format('Y-m-d').' by '.$card->redeemer?->email : 'No' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">No gift cards.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $cards->links() }}

    <h2>Create gift card</h2>
    <form method="post" action="{{ route('admin.gift-cards.store') }}" class="stack">
        @csrf
        <x-field name="amount" label="Amount" inputmode="decimal" required />
        <x-select name="currency" label="Currency" :options="array_combine($currencies, $currencies)" />
        <x-field name="expires_at" label="Expires on" type="date" />
        <button type="submit">Create gift card</button>
    </form>
@endsection
