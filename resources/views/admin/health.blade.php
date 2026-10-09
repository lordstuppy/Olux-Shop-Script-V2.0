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
    <p class="hint">{{ __('Load balancers and monitoring should use GET /health (and /metrics with the bearer token); this page is for people.') }}</p>
@endsection
