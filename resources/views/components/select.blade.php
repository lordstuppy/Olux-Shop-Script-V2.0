@props(['name', 'label', 'options' => [], 'value' => null, 'hint' => null, 'id' => null, 'placeholder' => null])
@php
    $id = $id ?? 'f-'.$name;
    $error = $errors->first($name);
    $selected = (string) old($name, $value);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<div class="field">
    <label for="{{ $id }}">{{ $label }}</label>
    @if ($hint)
        <span class="hint" id="{{ $id }}-hint">{{ $hint }}</span>
    @endif
    <select id="{{ $id }}" name="{{ $name }}"
        @if ($error) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes }}>
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected($selected === (string) $optionValue)>{{ $optionLabel }}</option>
        @endforeach
    </select>
    @if ($error)
        <span class="error-text" id="{{ $id }}-error">{{ $error }}</span>
    @endif
</div>
