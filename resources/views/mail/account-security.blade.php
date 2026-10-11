{!! $summary !!}

{!! __('Account:') !!} {!! $user->email !!}
{!! __('Time: :time UTC', ['time' => $time]) !!}

{!! __('If this was you, no action is needed.') !!}
{!! __('If it was not you, reset your password now and sign out other sessions:') !!} {!! route('account.settings') !!}
