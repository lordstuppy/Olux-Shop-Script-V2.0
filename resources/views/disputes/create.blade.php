@extends('layouts.app')

@section('title', __('Report a problem'))
@section('noindex', true)

@section('content')
    <h1>{{ __('Report a problem with ":title"', ['title' => $item->title]) }}</h1>
    <p>{{ __('Order') }} <a class="mono" href="{{ route('orders.show', $order) }}">{{ $order->shortId() }}</a> &middot; {{ money($item->netMinor(), $order->currency) }}</p>
    <p>{{ __('The seller is asked to respond first. If they do not answer in time, or you cannot agree, our team decides: a refund, a replacement, or no action. You can open a dispute until :date UTC.', ['date' => $until->format('Y-m-d H:i')]) }}</p>

    <form method="post" action="{{ route('disputes.store', [$order, $item]) }}" class="stack" data-once>
        @csrf
        <x-select name="reason" :label="__('What went wrong?')" :options="collect(\App\Enums\DisputeReason::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()])->all()" required />
        <fieldset>
            <legend>{{ __('What would you like?') }}</legend>
            <label class="check"><input type="radio" name="requested_outcome" value="replacement" @checked(old('requested_outcome', 'replacement') === 'replacement')> {{ __('A replacement (for example a working key)') }}</label>
            <label class="check"><input type="radio" name="requested_outcome" value="refund" @checked(old('requested_outcome') === 'refund')> {{ __('A refund') }}</label>
        </fieldset>
        <x-textarea name="body" :label="__('Describe the problem')" :hint="__('Include error messages and what you already tried. Do not post passwords.')" maxlength="5000" required />
        <button type="submit">{{ __('Open dispute') }}</button>
    </form>
@endsection
