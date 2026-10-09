@extends('layouts.app')

@section('title', 'Tickets - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Support tickets</h1>
    <form class="filters" method="get">
        <x-select name="status" label="Status" :options="['open' => 'Open', 'answered' => 'Answered', 'closed' => 'Closed']" :value="$filters['status'] ?? 'open'" />
        <x-select name="assigned" label="Assigned" :options="['any' => 'Anyone', 'me' => 'Me', 'none' => 'Nobody']" :value="$filters['assigned'] ?? 'any'" />
        <button type="submit">Filter</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Ticket</th><th scope="col">Subject</th><th scope="col">Category</th><th scope="col">User</th><th scope="col">Order</th><th scope="col">Assigned</th><th scope="col">Updated</th></tr></thead>
            <tbody>
                @forelse ($tickets as $ticket)
                    <tr>
                        <td><a href="{{ route('tickets.show', $ticket) }}">#{{ $ticket->id }}</a></td>
                        <td>{{ $ticket->subject }}</td>
                        <td>{{ str_replace('_', ' ', $ticket->category->value) }}</td>
                        <td>{{ $ticket->user->email }}</td>
                        <td class="mono">{{ $ticket->order?->shortId() ?? '-' }}</td>
                        <td>{{ $ticket->assignee?->email ?? '-' }}</td>
                        <td>{{ $ticket->updated_at->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7">No tickets.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $tickets->links() }}
@endsection
