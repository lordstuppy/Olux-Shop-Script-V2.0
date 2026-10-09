@extends('layouts.app')

@section('title', 'Seller applications - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Seller applications</h1>
    @forelse ($profiles as $profile)
        <section class="card mt" aria-labelledby="profile-{{ $profile->id }}">
            <h2 id="profile-{{ $profile->id }}">{{ $profile->display_name }} <x-status :value="$profile->status" /></h2>
            <p><a href="{{ route('admin.users.show', $profile->user) }}">{{ $profile->user->email }}</a> &middot; applied {{ $profile->created_at->format('Y-m-d') }} &middot; payout {{ $profile->payout_currency }} to <span class="mono">{{ $profile->payout_address }}</span></p>
            @if ($profile->about)
                <div class="description">{{ $profile->about }}</div>
            @endif
            @if ($profile->review_note)
                <p>Review note: {{ $profile->review_note }}</p>
            @endif
            @if ($profile->status === \App\Enums\SellerProfileStatus::Pending)
                <div class="actions mt">
                    <form method="post" action="{{ route('admin.sellers.approve', $profile) }}" class="actions">
                        @csrf
                        <x-field name="commission_bps" :id="'commission-'.$profile->id" label="Commission override (basis points)" type="number" min="0" max="10000" hint="Empty uses the default of {{ config('shop.commission_bps') }}." />
                        <button type="submit">Approve</button>
                    </form>
                    <form method="post" action="{{ route('admin.sellers.reject', $profile) }}" class="actions">
                        @csrf
                        <x-field name="note" :id="'note-'.$profile->id" label="Rejection reason" maxlength="500" required />
                        <button type="submit" class="btn-danger">Reject</button>
                    </form>
                </div>
            @endif
        </section>
    @empty
        <p>No applications.</p>
    @endforelse
    {{ $profiles->links() }}
@endsection
