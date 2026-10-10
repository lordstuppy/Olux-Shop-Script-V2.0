{!! __('A change of your account email to :email was requested. It takes effect only after the new address confirms it.', ['email' => $newEmail]) !!}

{!! __('If this was not you, change your password now and sign out other sessions:') !!} {!! route('account.settings') !!}
