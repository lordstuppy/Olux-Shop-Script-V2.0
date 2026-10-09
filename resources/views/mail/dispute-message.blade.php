{!! __('There is a new message from the :from on dispute #:id about ":title".', ['from' => match ($fromRole) { 'buyer' => __('buyer'), 'seller' => __('seller'), default => __('support team') }, 'id' => $dispute->id, 'title' => $dispute->item->title]) !!}

{!! __('Read and reply:') !!} {{ route('disputes.show', $dispute) }}
