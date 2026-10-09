@props(['name', 'label', 'value' => null, 'hint' => null, 'id' => null])
@php
    $id = $id ?? 'f-'.$name;
    $error = $errors->first($name);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<div class="field">
    <label for="{{ $id }}">{{ $label }}</label>
    @if ($hint)
        <span class="hint" id="{{ $id }}-hint">{{ $hint }}</span>
    @endif
    <textarea id="{{ $id }}" name="{{ $name }}"
        @if ($error) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes }}>{{ old($name, $value) }}</textarea>
    @if ($error)
        <span class="error-text" id="{{ $id }}-error">{{ $error }}</span>
    @endif
</div>
