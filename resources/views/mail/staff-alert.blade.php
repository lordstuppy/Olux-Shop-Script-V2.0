{!! $alertSubject !!}

@foreach ($lines as $line)
{!! $line !!}
@endforeach
@if ($url)

{!! __('Open:') !!} {!! $url !!}
@endif

{!! __('This is an automatic message for the :shop team.', ['shop' => config('app.name')]) !!}
