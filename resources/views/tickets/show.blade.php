@extends('layouts.app')

@section('title', 'Ticket #'.$ticket->id)
@section('noindex', true)

@section('content')
    <h1>Ticket #{{ $ticket->id }}: {{ $ticket->subject }}</h1>
    <p>
        Status: <x-status :value="$ticket->status" />
        @if ($ticket->order)
            &middot; Order <a href="{{ auth()->user()->isAdmin() ? route('admin.orders.show', $ticket->order) : route('orders.show', $ticket->order) }}" class="mono">{{ $ticket->order->shortId() }}</a>
        @endif
        @if (auth()->user()->isAdmin())
            &middot; Opened by <a href="{{ route('admin.users.show', $ticket->user) }}">{{ $ticket->user->email }}</a>
        @endif
    </p>

    <h2>Conversation</h2>
    @foreach ($ticket->messages as $message)
        @php $staff = $message->author_id !== $ticket->user_id; @endphp
        <article class="message {{ $staff ? 'message-staff' : '' }}">
            <p class="meta">{{ $staff ? 'Support team' : $message->author->name }} &middot; {{ $message->created_at->format('Y-m-d H:i') }} UTC</p>
            <div class="description">{{ $message->body }}</div>
        </article>
    @endforeach

    @if ($ticket->status !== \App\Enums\TicketStatus::Closed)
        <h2>Reply</h2>
        <form method="post" action="{{ route('tickets.reply', $ticket) }}" class="stack">
            @csrf
            <x-textarea name="body" label="Your reply" maxlength="5000" required />
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
