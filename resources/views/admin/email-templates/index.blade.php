@extends('layouts.app')

@section('title', __('Email templates - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Email templates') }}</h1>
    <p>{{ __('Change the subject and text of the emails the shop sends. Emails you have not changed use the built-in text. Edited texts are plain text and apply to every recipient of that email.') }}</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">{{ __('Email') }}</th><th scope="col">{{ __('Sent to') }}</th><th scope="col">{{ __('Text') }}</th></tr></thead>
            <tbody>
                @foreach ($definitions as $key => $definition)
                    <tr>
                        <td><a href="{{ route('admin.email-templates.edit', $key) }}">{{ $definition['label'] }}</a></td>
                        <td>{{ $definition['audience'] }}</td>
                        <td>
                            @if ($overrides->has($key))
                                <span class="status status-active">{{ __('Customised') }}</span> <span class="muted">{{ \Illuminate\Support\Carbon::parse($overrides[$key]->updated_at)->format('Y-m-d H:i') }} UTC</span>
                            @else
                                <span class="status">{{ __('Built-in') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
