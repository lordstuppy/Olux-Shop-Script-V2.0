{{--
    Irreversible action behind a confirmation step that works without
    JavaScript: the first button only opens a panel that names the
    consequence; the action runs from the second button. Opening the panel
    again closes it (nothing is sent).
--}}
@props(['action', 'method' => 'POST', 'label', 'confirm', 'message', 'context' => null, 'summaryClass' => 'btn-danger'])
<details class="confirm" {{ $attributes }}>
    <summary class="{{ $summaryClass }}">{{ $label }}@if ($context)<span class="visually-hidden"> {{ $context }}</span>@endif</summary>
    <div class="confirm-panel">
        <p>{{ $message }}</p>
        <form method="post" action="{{ $action }}" class="actions">
            @csrf
            @if (strtoupper($method) !== 'POST')
                @method($method)
            @endif
            {{ $slot }}
            <button type="submit" class="btn-danger">{{ $confirm }}@if ($context)<span class="visually-hidden"> {{ $context }}</span>@endif</button>
        </form>
    </div>
</details>
