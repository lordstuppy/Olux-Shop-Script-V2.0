{!! __('There were :attempts failed sign-in attempts on your account :email, so sign-in is paused for :minutes minutes.', ['attempts' => $attempts, 'email' => $user->email, 'minutes' => $minutes]) !!}

{!! __('If this was you, wait and try again, or reset your password to sign in right away:') !!} {!! route('password.request') !!}
{!! __('If it was not you, your password was not accepted. Resetting it and turning on two-factor authentication keeps the account safe.') !!}
