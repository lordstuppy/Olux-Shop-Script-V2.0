@extends('layouts.app')

@section('title', __('System health - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('System health') }}</h1>
    <p>
        <span class="level level-{{ $overall }}">{{ match ($overall) { 'good' => __('All OK'), 'warning' => __('Needs a look'), default => __('Problem') } }}</span>
        {{ __('Checked :time UTC.', ['time' => $checkedAt->format('Y-m-d H:i:s')]) }} <a href="{{ route('admin.health') }}">{{ __('Check again') }}</a>
    </p>

    @foreach ($checks as $section => $rows)
        <section aria-labelledby="health-{{ $loop->index }}">
            <h2 id="health-{{ $loop->index }}">{{ $section }}</h2>
            <ul class="health">
                @foreach ($rows as $check)
                    <li>
                        <span class="level level-{{ $check['level'] }}">{{ match ($check['level']) { 'good' => __('OK'), 'warning' => __('Check'), default => __('Problem') } }}</span>
                        <span><strong>{{ $check['label'] }}:</strong> {{ $check['value'] }}@if ($check['hint'] !== '')<br><span class="hint">{{ $check['hint'] }}</span>@endif</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
    <section aria-labelledby="failed-jobs">
        <h2 id="failed-jobs">{{ __('Failed background jobs') }} ({{ $failedJobCount }})</h2>
        @if ($failedJobs->isEmpty())
            <p>{{ __('None. Emails, deliveries and invoices that keep failing after their retries would be listed here.') }}</p>
        @else
            <p class="hint">{{ __('These jobs used up their retries. Fix the cause (for example the mail server), then queue them again. Deleting a job drops it for good.') }}</p>
            @can('jobs.manage')
                <form method="post" action="{{ route('admin.health.jobs.retry-all') }}">
                    @csrf
                    <button type="submit">{{ __('Retry all failed jobs') }}</button>
                </form>
            @endcan
            <div class="table-wrap mt" tabindex="0">
                <table>
                    <thead><tr><th scope="col">{{ __('Job') }}</th><th scope="col">{{ __('Failed at (UTC)') }}</th><th scope="col">{{ __('Error') }}</th>@can('jobs.manage')<th scope="col">{{ __('Actions') }}</th>@endcan</tr></thead>
                    <tbody>
                        @foreach ($failedJobs as $job)
                            <tr>
                                <td>{{ $job['name'] }}<br><span class="hint mono">{{ $job['uuid'] }}</span></td>
                                <td>{{ $job['failed_at'] }}</td>
                                <td>{{ $job['error'] }}</td>
                                @can('jobs.manage')
                                    <td class="actions">
                                        <form method="post" action="{{ route('admin.health.jobs.retry', $job['uuid']) }}">
                                            @csrf
                                            <button type="submit" class="btn-secondary">{{ __('Retry') }}</button>
                                        </form>
                                        <x-confirm :action="route('admin.health.jobs.forget', $job['uuid'])" method="DELETE" :label="__('Delete')" :context="$job['uuid']"
                                            :message="__('The job is discarded and never retried. This cannot be undone.')" :confirm="__('Yes, delete job')" />
                                    </td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($failedJobCount > $failedJobs->count())
                <p class="hint">{{ __('Showing the latest :shown of :total.', ['shown' => $failedJobs->count(), 'total' => $failedJobCount]) }}</p>
            @endif
        @endif
    </section>

    <p class="hint">{{ __('Load balancers and monitoring should use GET /health (and /metrics with the bearer token); this page is for people.') }}</p>
@endsection
