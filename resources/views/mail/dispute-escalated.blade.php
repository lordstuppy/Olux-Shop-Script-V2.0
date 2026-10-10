{!! __('The seller did not respond to dispute #:id about ":title" in time, so our team will now review it.', ['id' => $dispute->id, 'title' => $dispute->item->title]) !!}

{!! __('Open the dispute:') !!} {!! route('disputes.show', $dispute) !!}
