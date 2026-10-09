@if (session('success'))
    <div class="flash flash-success" role="status">{{ session('success') }}</div>
@endif
@if (session('info'))
    <div class="flash flash-info" role="status">{{ session('info') }}</div>
@endif
@if (session('bulk_skipped'))
    <div class="flash flash-info" role="status">
        <p><strong>{{ __('Skipped rows:') }}</strong></p>
        <ul>
            @foreach (session('bulk_skipped') as $reason)
                <li>{{ $reason }}</li>
            @endforeach
        </ul>
    </div>
@endif
@if (session('error'))
    <div class="flash flash-error" role="alert">{{ session('error') }}</div>
@endif
@if ($errors->any())
    <div class="flash flash-error" role="alert">
        <p><strong>{{ trans_choice('{1} Please correct the following problem:|[2,*] Please correct the following :count problems:', $errors->count()) }}</strong></p>
        <ul>
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
