@extends('layouts.app')

@section('title', 'Settings - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Shop settings</h1>
    <p>These values override the defaults from the environment. Changes apply immediately and are recorded in the audit log.</p>
    <form method="post" action="{{ route('admin.settings.update') }}" class="stack">
        @csrf
        @method('PUT')
        @foreach ($fields as $key => $field)
            <x-field :name="$key" :label="$field['label']" :type="$key === 'support_email' ? 'email' : 'number'" :value="$field['value']" :hint="$field['hint'] ?: null" required />
        @endforeach
        <button type="submit">Save settings</button>
    </form>
@endsection
