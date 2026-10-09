@props(['value'])
@php $raw = $value instanceof \BackedEnum ? $value->value : (string) $value; @endphp
<span class="status status-{{ $raw }}">{{ $value instanceof \App\Enums\OrderStatus ? $value->label() : str_replace('_', ' ', ucfirst($raw)) }}</span>
