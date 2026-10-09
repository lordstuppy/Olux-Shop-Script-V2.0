@extends('layouts.app')

@section('title', 'Audit log - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Audit log</h1>
    <form class="filters" method="get">
        <x-field name="action" label="Action starts with" :value="$filters['action'] ?? ''" maxlength="64" hint="e.g. order., refund., payout." />
        <x-field name="actor" label="Actor user id" type="number" :value="$filters['actor'] ?? ''" />
        <x-field name="target_type" label="Target type" :value="$filters['target_type'] ?? ''" maxlength="64" />
        <x-field name="target_id" label="Target id" :value="$filters['target_id'] ?? ''" maxlength="64" />
        <x-field name="from" label="From" type="date" :value="$filters['from'] ?? ''" />
        <x-field name="to" label="To" type="date" :value="$filters['to'] ?? ''" />
        <button type="submit">Filter</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Time (UTC)</th><th scope="col">Actor</th><th scope="col">Action</th><th scope="col">Target</th><th scope="col">Details</th><th scope="col">IP / request</th></tr></thead>
            <tbody>
                @forelse ($entries as $entry)
                    <tr>
                        <td>{{ $entry->created_at->format('Y-m-d H:i:s') }}</td>
                        <td>{{ $entry->actor?->email ?? 'system' }}</td>
                        <td class="mono">{{ $entry->action }}</td>
                        <td>{{ $entry->target_type }} {{ $entry->target_id }}</td>
                        <td class="mono">{{ $entry->metadata_json ? json_encode($entry->metadata_json, JSON_UNESCAPED_SLASHES) : '' }}</td>
                        <td class="mono">{{ $entry->ip }}<br>{{ $entry->request_id }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">No entries match.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $entries->links() }}
@endsection
