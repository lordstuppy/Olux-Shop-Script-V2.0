@extends('layouts.minimal')

@section('title', 'Too many requests')

@section('content')
    <h1>Too many requests</h1>
    <p>{{ $reason ?? 'You sent too many requests in a short time. Wait a minute and try again.' }}</p>
@endsection
