Your account {{ $user->email }} was signed in from a new device.

Time: {{ $device->created_at->format('Y-m-d H:i') }} UTC
IP address: {{ $device->ip }}
Browser: {{ $device->user_agent }}

If this was you, no action is needed.
If it was not you, change your password now and review signed-in sessions: {{ route('account.settings') }}
