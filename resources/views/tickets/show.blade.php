@extends('layouts.app')

@section('title', 'Ticket #'.$ticket->id)
@section('noindex', true)

@section('content')
    <h1>Ticket #{{ $ticket->id }}: {{ $ticket->subject }}</h1>
    <p>
        Status: <x-status :value="$ticket->status" />
        @if ($ticket->order)
            &middot; Order <a href="{{ auth()->user()->can('orders.view') ? route('admin.orders.show', $ticket->order) : route('orders.show', $ticket->order) }}" class="mono">{{ $ticket->order->shortId() }}</a>
        @endif
        @if (auth()->user()->can('users.view'))
            &middot; Opened by <a href="{{ route('admin.users.show', $ticket->user) }}">{{ $ticket->user->email }}</a>
        @endif
    </p>

    @if ($staff)
        <form method="post" action="{{ route('tickets.assign', $ticket) }}" class="actions">
            @csrf
            <x-select name="assigned_to" label="Assigned to" :options="$assignees->pluck('email', 'id')->all()" :value="$ticket->assigned_to" placeholder="Nobody" />
            <button type="submit" class="btn-secondary">Assign</button>
        </form>
    @endif

    <h2>Conversation</h2>
    @foreach ($messages as $message)
        @php $fromStaff = $message->author_id !== $ticket->user_id; @endphp
        <article class="message {{ $message->internal ? 'message-internal' : ($fromStaff ? 'message-staff' : '') }}">
            <p class="meta">
                @if ($message->internal) <strong>Internal note</strong> by {{ $message->author->email }}
                @elseif ($fromStaff) Support team{{ $staff ? ' ('.$message->author->email.')' : '' }}
                @else {{ $message->author->name }} @endif
                &middot; {{ $message->created_at->format('Y-m-d H:i') }} UTC
            </p>
            <div class="description">{{ $message->body }}</div>
        </article>
    @endforeach

    @if ($ticket->status !== \App\Enums\TicketStatus::Closed)
        <h2>Reply</h2>
        <form method="post" action="{{ route('tickets.reply', $ticket) }}" class="stack">
            @csrf
            <x-textarea name="body" label="Your reply" maxlength="5000" required />
            @if ($staff)
                <label class="check"><input type="checkbox" name="internal" value="1"> Internal note (not visible to the customer, no email)</label>
            @endif
            <button type="submit">Send reply</button>
        </form>
        <form method="post" action="{{ route('tickets.close', $ticket) }}" class="mt">
            @csrf
            <button type="submit" class="btn-secondary">Close ticket</button>
        </form>
    @else
        <p>This ticket is closed. <a href="{{ route('tickets.create') }}">Open a new ticket</a> if you need more help.</p>
    @endif
@endsection
