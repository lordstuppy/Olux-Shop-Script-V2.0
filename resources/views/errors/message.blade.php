@extends('layouts.minimal')

@section('title', __('Cannot continue'))

@section('content')
    <h1>{{ __('Cannot continue') }}</h1>
    <p>{{ $message }}</p>
@endsection
