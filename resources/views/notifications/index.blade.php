@extends('layouts.app')

@section('title', __('Notifications'))
@section('noindex', true)

@section('content')
    <h1>{{ __('Notifications') }}</h1>
    @if ($notifications->isEmpty())
        <p>{{ __('Nothing here yet. Orders, payments, disputes, support replies and account events show up here as they happen.') }} <a href="{{ route('products.index') }}">{{ __('Browse products') }}</a>.</p>
    @else
        <div class="actions">
            <form method="post" action="{{ route('notifications.read-all') }}">
                @csrf
                <button type="submit" class="btn-secondary">{{ __('Mark all as read') }}</button>
            </form>
            <a href="{{ route('account.settings') }}#notifications">{{ __('Notification settings') }}</a>
        </div>
        <ul class="notifications">
            @foreach ($notifications as $notification)
                <li class="notification {{ $notification->read_at === null ? 'is-unread' : '' }}">
                    <a href="{{ route('notifications.open', $notification->id) }}">
                        @if ($notification->read_at === null)<span class="visually-hidden">{{ __('Unread:') }} </span>@endif
                        <strong>{{ $notification->title }}</strong>
                    </a>
                    @if ($notification->body)
                        <div class="notification-body">{{ $notification->body }}</div>
                    @endif
                    <span class="meta"><time datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->format('Y-m-d H:i') }} UTC</time></span>
                </li>
            @endforeach
        </ul>
        {{ $notifications->links() }}
    @endif
@endsection
