{!! __('Dispute #:id about ":title" is closed.', ['id' => $dispute->id, 'title' => $dispute->item->title]) !!}

{!! __('Outcome:') !!} {!! $dispute->resolution?->label() !!}
@if ($dispute->refund_minor)
{!! __('Refund:') !!} {!! money($dispute->refund_minor, $dispute->order->currency) !!}
@endif
@if ($dispute->resolution_note)

{!! $dispute->resolution_note !!}
@endif

{!! __('Details:') !!} {!! route('disputes.show', $dispute) !!}
