{{-- One-time key for forms behind the submit.once middleware: a second send of the same form is not processed again. --}}
<input type="hidden" name="_once" value="{{ \Illuminate\Support\Str::uuid() }}">
