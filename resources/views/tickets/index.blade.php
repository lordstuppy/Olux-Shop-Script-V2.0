@extends('layouts.app')

@section('title', 'Support')
@section('noindex', true)

@section('content')
    <h1>Support tickets</h1>
    <p><a class="btn" href="{{ route('tickets.create') }}">Open a new ticket</a></p>
    @if ($tickets->isEmpty())
        <p>You have no tickets.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th scope="col">Ticket</th><th scope="col">Subject</th><th scope="col">Order</th><th scope="col">Status</th><th scope="col">Updated</th></tr>
                </thead>
                <tbody>
                    @foreach ($tickets as $ticket)
                        <tr>
                            <td><a href="{{ route('tickets.show', $ticket) }}">#{{ $ticket->id }}</a></td>
                            <td>{{ $ticket->subject }}</td>
                            <td>{{ $ticket->order?->shortId() ?? '-' }}</td>
                            <td><x-status :value="$ticket->status" /></td>
                            <td>{{ $ticket->updated_at->format('Y-m-d H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $tickets->links() }}
    @endif
@endsection
