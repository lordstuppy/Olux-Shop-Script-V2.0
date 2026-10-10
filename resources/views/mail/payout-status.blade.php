{!! __('Payout #:id of :amount is now :status.', ['id' => $payout->id, 'amount' => money($payout->amount_minor, $payout->currency), 'status' => mb_strtolower($payout->status->label())]) !!}

@if ($payout->status === \App\Enums\PayoutStatus::Paid)
{!! $payout->crypto_amount ? __('Sent to :destination as :amount :crypto.', ['destination' => $payout->destination, 'amount' => $payout->crypto_amount, 'crypto' => $payout->crypto]) : __('Sent to :destination.', ['destination' => $payout->destination]) !!}
{!! __('Transaction reference:') !!} {!! $payout->reference !!}
@elseif ($payout->status === \App\Enums\PayoutStatus::Rejected)
{!! __('Reason:') !!} {!! $payout->note !!}
{!! __('The amount is available in your seller balance again.') !!}
@endif

{!! __('Payout history:') !!} {!! route('seller.payouts') !!}
