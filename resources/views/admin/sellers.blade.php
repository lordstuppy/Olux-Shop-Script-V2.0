@extends('layouts.app')

@section('title', __('Seller applications - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Seller applications') }}</h1>
    @forelse ($profiles as $profile)
        <section class="card mt" aria-labelledby="profile-{{ $profile->id }}">
            <h2 id="profile-{{ $profile->id }}">{{ $profile->display_name }} <x-status :value="$profile->status" /></h2>
            <p><a href="{{ route('admin.users.show', $profile->user) }}">{{ $profile->user->email }}</a> &middot; {{ __('applied :date', ['date' => $profile->created_at->format('Y-m-d')]) }} &middot; {{ __('payout :currency to', ['currency' => $profile->payout_currency]) }} <span class="mono">{{ $profile->payout_address }}</span></p>
            @if ($profile->about)
                <div class="description">{{ $profile->about }}</div>
            @endif
            @if ($profile->review_note)
                <p>{{ __('Review note: :note', ['note' => $profile->review_note]) }}</p>
            @endif
            @if ($profile->status === \App\Enums\SellerProfileStatus::Pending)
                <div class="actions mt">
                    <form method="post" action="{{ route('admin.sellers.approve', $profile) }}" class="actions">
                        @csrf
                        <x-field name="commission_bps" :id="'commission-'.$profile->id" :label="__('Commission override (basis points)')" type="number" min="0" max="10000" :hint="__('Empty uses the default of :bps.', ['bps' => config('shop.commission_bps')])" />
                        <button type="submit">{{ __('Approve') }}</button>
                    </form>
                    <form method="post" action="{{ route('admin.sellers.reject', $profile) }}" class="actions">
                        @csrf
                        <x-field name="note" :id="'note-'.$profile->id" :label="__('Rejection reason')" maxlength="500" required />
                        <button type="submit" class="btn-danger">{{ __('Reject') }}</button>
                    </form>
                </div>
            @endif
        </section>
    @empty
        <p>{{ __('No applications.') }}</p>
    @endforelse
    {{ $profiles->links() }}
@endsection
