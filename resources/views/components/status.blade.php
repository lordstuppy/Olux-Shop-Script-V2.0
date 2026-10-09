@props(['value', 'label' => null])
@php $raw = $value instanceof \BackedEnum ? $value->value : (string) $value; @endphp
<span class="status status-{{ $raw }}">{{ $label ?? ($value instanceof \BackedEnum ? $value->label() : str_replace('_', ' ', ucfirst($raw))) }}</span>
