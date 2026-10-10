{!! __('A change of the payout address for :shop was requested.', ['shop' => $profile->display_name]) !!}

{!! __('New address: :address (:crypto)', ['address' => $profile->pending_payout_address, 'crypto' => $profile->pending_payout_crypto]) !!}

{!! __('To confirm, sign in and open this link within 24 hours:') !!}
{!! $confirmUrl !!}

{!! __('If you did not request this, do not open the link. Change your password and turn on two-factor authentication:') !!} {!! route('account.settings') !!}
