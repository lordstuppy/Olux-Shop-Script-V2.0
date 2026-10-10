{!! __('Someone asked to use this address for the :app account currently registered as :email.', ['app' => config('app.name'), 'email' => $user->email]) !!}

{!! __('To confirm, sign in and open this link within 24 hours:') !!}
{!! $confirmUrl !!}

{!! __('If you did not ask for this, ignore this email; nothing changes.') !!}
