@if (session('success'))
    <div class="flash flash-success" role="status">{{ session('success') }}</div>
@endif
@if (session('info'))
    <div class="flash flash-info" role="status">{{ session('info') }}</div>
@endif
@if (session('error'))
    <div class="flash flash-error" role="alert">{{ session('error') }}</div>
@endif
@if ($errors->any())
    <div class="flash flash-error" role="alert">
        <p><strong>Please correct the following {{ $errors->count() === 1 ? 'problem' : $errors->count().' problems' }}:</strong></p>
        <ul>
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
