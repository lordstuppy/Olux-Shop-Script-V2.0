@extends('layouts.app')

@section('title', __('Gift cards - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Gift cards') }}</h1>
    <p>{{ __('Codes are stored only as a keyed hash. The full code is shown once, right after creation.') }}</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">#</th><th scope="col">{{ __('Code ends') }}</th><th scope="col" class="num">{{ __('Amount') }}</th><th scope="col">{{ __('Created') }}</th><th scope="col">{{ __('Expires') }}</th><th scope="col">{{ __('Redeemed') }}</th></tr></thead>
            <tbody>
                @forelse ($cards as $card)
                    <tr>
                        <td>{{ $card->id }}</td>
                        <td class="mono">...{{ $card->code_last4 }}</td>
                        <td class="num">{{ money($card->amount_minor, $card->currency) }}</td>
                        <td>{{ $card->created_at->format('Y-m-d') }}</td>
                        <td>{{ $card->expires_at?->format('Y-m-d') ?? __('Never') }}</td>
                        <td>{{ $card->redeemed_at ? __(':date by :email', ['date' => $card->redeemed_at->format('Y-m-d'), 'email' => $card->redeemer?->email ?? '']) : __('No') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">{{ __('No gift cards.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $cards->links() }}

    <h2>{{ __('Create gift card') }}</h2>
    <form method="post" action="{{ route('admin.gift-cards.store') }}" class="stack">
        @csrf
        <x-field name="amount" :label="__('Amount')" inputmode="decimal" required />
        <x-select name="currency" :label="__('Currency')" :options="array_combine($currencies, $currencies)" />
        <x-field name="expires_at" :label="__('Expires on')" type="date" />
        <button type="submit">{{ __('Create gift card') }}</button>
    </form>
@endsection
