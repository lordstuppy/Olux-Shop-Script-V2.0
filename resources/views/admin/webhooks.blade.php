@extends('layouts.app')

@section('title', 'Webhook events - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Webhook events</h1>
    <p>Failed events are retried automatically with exponential backoff ({{ implode(', ', config('shop.webhook_retry_backoff')) }} seconds). Events that exhaust all retries are marked dead.</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">#</th><th scope="col">Received</th><th scope="col">Order</th><th scope="col">Status</th><th scope="col" class="num">Attempts</th><th scope="col">Next attempt</th><th scope="col">Detail</th><th scope="col">Action</th></tr></thead>
            <tbody>
                @forelse ($events as $event)
                    <tr>
                        <td>{{ $event->id }}</td>
                        <td>{{ $event->created_at->format('Y-m-d H:i:s') }}</td>
                        <td class="mono">{{ $event->external_id }}</td>
                        <td><x-status :value="$event->status" /></td>
                        <td class="num">{{ $event->attempts }}</td>
                        <td>{{ $event->next_attempt_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                        <td>{{ $event->last_error }}</td>
                        <td>
                            @if (in_array($event->status, [\App\Enums\WebhookEventStatus::Failed, \App\Enums\WebhookEventStatus::Dead], true))
                                <form method="post" action="{{ route('admin.webhooks.retry', $event) }}">
                                    @csrf
                                    <button type="submit" class="btn-secondary">Retry now<span class="visually-hidden"> event {{ $event->id }}</span></button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8">No webhook events received.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $events->links() }}
@endsection
