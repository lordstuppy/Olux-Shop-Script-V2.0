@extends('layouts.app')

@section('title', __('Edit email - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <p><a href="{{ route('admin.email-templates.index') }}">{{ __('All email templates') }}</a></p>
    <h1>{{ $definition['label'] }}</h1>
    <p>{{ __('Sent to: :audience.', ['audience' => $definition['audience']]) }}
        {{ $customised ? __('This email uses your text.') : __('This email uses the built-in text; the form below starts from it.') }}</p>

    <div class="two-col">
        <form method="post" action="{{ route('admin.email-templates.update', $key) }}" class="stack">
            @csrf
            @method('PUT')
            <x-field name="subject" :label="__('Subject')" :value="old('subject', $template['subject'])" maxlength="200" required />
            <x-textarea name="body" :label="__('Text')" :value="old('body', $template['body'])" maxlength="10000" rows="16" required />
            <div class="actions">
                <button type="submit" name="preview" value="1" class="btn-secondary">{{ __('Preview with sample data') }}</button>
                <button type="submit">{{ __('Save') }}</button>
            </div>
        </form>

        <section aria-labelledby="placeholders-heading">
            <h2 id="placeholders-heading">{{ __('Placeholders') }}</h2>
            <p class="hint">{{ __('Type a placeholder with its braces; it is replaced when the email is sent.') }}</p>
            <dl class="facts">
                @foreach (\App\Support\EmailTemplates::GLOBALS + $definition['placeholders'] as $name => $description)
                    <dt class="mono">{{ '{'.$name.'}' }}</dt>
                    <dd>{{ $description }}@if (in_array($name, $definition['required'], true)) <strong>({{ __('required') }})</strong>@endif</dd>
                @endforeach
            </dl>
        </section>
    </div>

    @if ($preview)
        <section class="card mt" aria-labelledby="preview-heading">
            <h2 id="preview-heading">{{ __('Preview (not saved)') }}</h2>
            <p><strong>{{ __('Subject:') }}</strong> {{ $preview['subject'] }}</p>
            <pre class="mail-preview">{{ $preview['body'] }}</pre>
        </section>
    @endif

    @if ($customised)
        <form method="post" action="{{ route('admin.email-templates.reset', $key) }}" class="mt">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-danger">{{ __('Reset to the built-in text') }}</button>
        </form>
    @endif
@endsection
