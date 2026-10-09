The payout address for {{ $profile->display_name }} is now {{ $profile->payout_address }} ({{ $profile->payout_crypto }}).

For your security, payouts are paused for {{ config('shop.payout_address_cooldown_hours') }} hours after the change.
If you did not make this change, contact {{ config('shop.support_email') }} immediately.
