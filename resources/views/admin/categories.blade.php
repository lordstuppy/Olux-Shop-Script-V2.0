@extends('layouts.app')

@section('title', 'Categories - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Categories</h1>
    <ul>
        @forelse ($categories as $category)
            <li>{{ $category->name }} (<span class="mono">{{ $category->slug }}</span>, {{ $category->products_count }} products)</li>
        @empty
            <li>No categories.</li>
        @endforelse
    </ul>
    <h2>Add category</h2>
    <form method="post" action="{{ route('admin.categories.store') }}" class="stack">
        @csrf
        <x-field name="name" label="Name" maxlength="80" required />
        <x-field name="description" label="Description" maxlength="500" />
        <button type="submit">Add category</button>
    </form>
@endsection
