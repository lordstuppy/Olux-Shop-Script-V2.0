A change of the payout address for {{ $profile->display_name }} was requested.

New address: {{ $profile->pending_payout_address }} ({{ $profile->pending_payout_crypto }})

To confirm, sign in and open this link within 24 hours:
{{ $confirmUrl }}

If you did not request this, do not open the link. Change your password and turn on two-factor authentication: {{ route('account.settings') }}
