@extends('layouts.app')

@section('title', __('Tickets - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Support tickets') }}</h1>
    <form class="filters" method="get">
        <x-select name="status" :label="__('Status')" :options="['open' => __('Open'), 'answered' => __('Answered'), 'closed' => __('Closed')]" :value="$filters['status'] ?? 'open'" />
        <x-select name="assigned" :label="__('Assigned')" :options="['any' => __('Anyone'), 'me' => __('Me'), 'none' => __('Nobody')]" :value="$filters['assigned'] ?? 'any'" />
        <button type="submit">{{ __('Filter') }}</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">{{ __('Ticket') }}</th><th scope="col">{{ __('Subject') }}</th><th scope="col">{{ __('Category') }}</th><th scope="col">{{ __('User') }}</th><th scope="col">{{ __('Order') }}</th><th scope="col">{{ __('Assigned') }}</th><th scope="col">{{ __('Updated') }}</th></tr></thead>
            <tbody>
                @forelse ($tickets as $ticket)
                    <tr>
                        <td><a href="{{ route('tickets.show', $ticket) }}">#{{ $ticket->id }}</a></td>
                        <td>{{ $ticket->subject }}</td>
                        <td>{{ $ticket->category->label() }}</td>
                        <td>{{ $ticket->user->email }}</td>
                        <td class="mono">{{ $ticket->order?->shortId() ?? '-' }}</td>
                        <td>{{ $ticket->assignee?->email ?? '-' }}</td>
                        <td>{{ $ticket->updated_at->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7">{{ __('No tickets.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $tickets->links() }}
@endsection
