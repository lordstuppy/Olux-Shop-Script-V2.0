@extends('layouts.minimal')

@section('title', __('Temporarily unavailable'))

@section('content')
    <h1>{{ __('Temporarily unavailable') }}</h1>
    <p>{{ __('The shop cannot reach its database right now. Please try again in a minute.') }}</p>
    <p>{{ __('If you were placing an order, check Your orders before trying again: an order is either complete or was not created, and you are never charged twice.') }}</p>
@endsection
