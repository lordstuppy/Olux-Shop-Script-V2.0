@extends('layouts.app')

@section('title', __('Reviews - Seller'))
@section('noindex', true)
@section('subnav') @include('partials.seller-nav') @endsection

@section('content')
    <h1>{{ __('Reviews of your products') }}</h1>
    <p class="hint">{{ __('Your reply is shown under the review on the product page. The reviewer is notified when you first reply; edits are not announced. Reviews without a reply are listed first.') }}</p>
    @forelse ($reviews as $review)
        <article class="message" id="review-{{ $review->id }}">
            <h2 class="card-title">{{ $review->title }} <span class="muted">&middot; <a href="{{ route('products.show', $review->product->slug) }}#review-{{ $review->id }}">{{ $review->product->title }}</a></span></h2>
            <p class="meta"><span aria-label="{{ __(':rating out of 5 stars', ['rating' => $review->rating]) }}">{{ str_repeat('*', $review->rating) }}{{ str_repeat('-', 5 - $review->rating) }}</span> &middot; {{ $review->user->name }} &middot; {{ $review->created_at->format('Y-m-d') }}
                @if ($review->status !== 'visible') &middot; <x-status value="hidden" /> @endif</p>
            <div class="description">{{ $review->body }}</div>
            @if ($review->status === 'visible')
                <form method="post" action="{{ route('seller.reviews.reply', $review->id) }}" class="stack mt">
                    @csrf
                    @method('PUT')
                    <x-textarea name="reply" :id="'reply-'.$review->id" :label="$review->seller_reply ? __('Your reply (leave empty to remove it)') : __('Your reply')" :value="$review->seller_reply" maxlength="2000" />
                    <button type="submit">{{ $review->seller_reply ? __('Update reply') : __('Publish reply') }}<span class="visually-hidden"> {{ __('to review :id', ['id' => $review->id]) }}</span></button>
                </form>
            @else
                <p class="hint">{{ __('Our team hid this review, so it cannot be answered.') }}</p>
            @endif
        </article>
    @empty
        <p>{{ __('No reviews yet. Buyers can review a product after they bought it.') }} <a href="{{ route('seller.products.index') }}">{{ __('Check your products') }}</a>.</p>
    @endforelse
    {{ $reviews->links() }}
@endsection
