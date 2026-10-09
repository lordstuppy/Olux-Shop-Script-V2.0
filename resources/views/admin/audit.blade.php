@extends('layouts.app')

@section('title', __('Audit log - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Audit log') }}</h1>
    <form class="filters" method="get">
        <x-field name="action" :label="__('Action starts with')" :value="$filters['action'] ?? ''" maxlength="64" :hint="__('e.g. :examples', ['examples' => 'order., refund., payout.'])" />
        <x-field name="actor" :label="__('Actor user id')" type="number" :value="$filters['actor'] ?? ''" />
        <x-field name="target_type" :label="__('Target type')" :value="$filters['target_type'] ?? ''" maxlength="64" />
        <x-field name="target_id" :label="__('Target id')" :value="$filters['target_id'] ?? ''" maxlength="64" />
        <x-field name="from" :label="__('From')" type="date" :value="$filters['from'] ?? ''" />
        <x-field name="to" :label="__('To')" type="date" :value="$filters['to'] ?? ''" />
        <button type="submit">{{ __('Filter') }}</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">{{ __('Time (UTC)') }}</th><th scope="col">{{ __('Actor') }}</th><th scope="col">{{ __('Action') }}</th><th scope="col">{{ __('Target') }}</th><th scope="col">{{ __('Details') }}</th><th scope="col">{{ __('IP / request') }}</th></tr></thead>
            <tbody>
                @forelse ($entries as $entry)
                    <tr>
                        <td>{{ $entry->created_at->format('Y-m-d H:i:s') }}</td>
                        <td>{{ $entry->actor?->email ?? __('system') }}</td>
                        <td class="mono">{{ $entry->action }}</td>
                        <td>{{ $entry->target_type }} {{ $entry->target_id }}</td>
                        <td class="mono">{{ $entry->metadata_json ? json_encode($entry->metadata_json, JSON_UNESCAPED_SLASHES) : '' }}</td>
                        <td class="mono">{{ $entry->ip }}<br>{{ $entry->request_id }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">{{ __('No entries match.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $entries->links() }}
@endsection
