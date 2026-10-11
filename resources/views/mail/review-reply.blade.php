{!! __('The seller of ":title" replied to your review.', ['title' => $review->product->title]) !!}

{!! $review->seller_reply !!}

{!! __('See it on the product page:') !!} {!! route('products.show', $review->product->slug) !!}#review-{!! $review->id !!}
