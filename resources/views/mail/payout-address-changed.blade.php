{!! __('The payout address for :shop is now :address (:crypto).', ['shop' => $profile->display_name, 'address' => $profile->payout_address, 'crypto' => $profile->payout_crypto]) !!}

{!! __('For your security, payouts are paused for :hours hours after the change.', ['hours' => config('shop.payout_address_cooldown_hours')]) !!}
{!! __('If you did not make this change, contact :email immediately.', ['email' => config('shop.support_email')]) !!}
