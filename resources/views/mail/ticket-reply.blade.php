{!! __('Our support team replied to ticket #:id: :subject', ['id' => $ticket->id, 'subject' => $ticket->subject]) !!}

{!! __('Read and reply:') !!} {!! route('tickets.show', $ticket) !!}
