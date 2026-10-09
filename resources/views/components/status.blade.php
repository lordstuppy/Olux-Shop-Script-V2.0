@props(['value'])
@php $raw = $value instanceof \BackedEnum ? $value->value : (string) $value; @endphp
<span class="status status-{{ $raw }}">{{ $value instanceof \BackedEnum ? $value->label() : str_replace('_', ' ', ucfirst($raw)) }}</span>
