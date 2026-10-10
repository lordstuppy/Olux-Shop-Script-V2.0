@php($item = $dispute->item)
@if ($recipientRole === 'seller')
{!! __('A buyer opened dispute #:id about ":title" (order :order).', ['id' => $dispute->id, 'title' => $item->title, 'order' => $dispute->order->shortId()]) !!}

{!! __('Reason:') !!} {!! $dispute->reason->label() !!}
{!! __('The buyer asks for:') !!} {!! $dispute->requested_outcome === 'refund' ? __('a refund') : __('a replacement') !!}

{!! __('Please respond by :time UTC. After that the case goes to our team without your answer. Your earnings for this item are held until the dispute is resolved.', ['time' => $dispute->seller_respond_by->format('Y-m-d H:i')]) !!}
@else
{!! __('Dispute #:id was opened about ":title" (order :order) and is waiting for the seller until :time UTC.', ['id' => $dispute->id, 'title' => $item->title, 'order' => $dispute->order->shortId(), 'time' => $dispute->seller_respond_by->format('Y-m-d H:i')]) !!}

{!! __('Reason:') !!} {!! $dispute->reason->label() !!}
@endif

{!! __('Open the dispute:') !!} {!! route('disputes.show', $dispute) !!}
