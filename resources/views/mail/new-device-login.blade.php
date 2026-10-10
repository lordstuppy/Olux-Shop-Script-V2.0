{!! __('Your account :email was signed in from a new device.', ['email' => $user->email]) !!}

{!! __('Time: :time UTC', ['time' => $device->created_at->format('Y-m-d H:i')]) !!}
{!! __('IP address:') !!} {!! $device->ip !!}
{!! __('Browser:') !!} {!! $device->user_agent !!}

{!! __('If this was you, no action is needed.') !!}
{!! __('If it was not you, change your password now and review signed-in sessions:') !!} {!! route('account.settings') !!}
