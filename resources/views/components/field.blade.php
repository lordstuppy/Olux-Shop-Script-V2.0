@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null, 'id' => null])
@php
    $id = $id ?? 'f-'.str_replace(['[', ']', '.'], '-', $name);
    $error = $errors->first($name);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<div class="field">
    <label for="{{ $id }}">{{ $label }}</label>
    @if ($hint)
        <span class="hint" id="{{ $id }}-hint">{{ $hint }}</span>
    @endif
    <input type="{{ $type }}" id="{{ $id }}" name="{{ $name }}"
        @if ($type !== 'password' && $type !== 'file') value="{{ old($name, $value) }}" @endif
        @if ($error) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes }}>
    @if ($error)
        <span class="error-text" id="{{ $id }}-error">{{ $error }}</span>
    @endif
</div>
