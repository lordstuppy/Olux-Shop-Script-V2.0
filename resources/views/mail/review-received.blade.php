{!! __('A buyer reviewed ":title" with :rating of 5 stars.', ['title' => $review->product->title, 'rating' => $review->rating]) !!}

{!! $review->title !!}
{!! $review->body !!}

{!! __('See it on the product page:') !!} {!! route('products.show', $review->product->slug) !!}

{!! __('Reviews that break the rules can be reported to our support team. You can turn these emails off in your account settings.') !!}
