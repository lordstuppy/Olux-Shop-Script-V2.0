Payout #{{ $payout->id }} of {{ money($payout->amount_minor, $payout->currency) }} is now {{ $payout->status->value }}.

@if ($payout->status === \App\Enums\PayoutStatus::Paid)
Sent to {{ $payout->destination }}@if ($payout->crypto_amount) as {{ $payout->crypto_amount }} {{ $payout->crypto }}@endif.
Transaction reference: {{ $payout->reference }}
@elseif ($payout->status === \App\Enums\PayoutStatus::Rejected)
Reason: {{ $payout->note }}
The amount is available in your seller balance again.
@endif

Payout history: {{ route('seller.payouts') }}
