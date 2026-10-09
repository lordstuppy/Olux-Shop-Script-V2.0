@extends('layouts.app')

@section('title', 'Categories - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Categories</h1>
    @forelse ($categories as $category)
        <section class="card mt" aria-labelledby="cat-{{ $category->id }}">
            <h2 id="cat-{{ $category->id }}">{{ $category->name }} <span class="muted">(<span class="mono">{{ $category->slug }}</span>, {{ $category->products_count }} products)</span></h2>
            <form method="post" action="{{ route('admin.categories.update', $category->id) }}" class="stack">
                @csrf
                @method('PUT')
                <x-field name="name" :id="'name-'.$category->id" label="Name" :value="$category->name" maxlength="80" required />
                <x-field name="description" :id="'desc-'.$category->id" label="Description" :value="$category->description" maxlength="500" />
                <div class="actions">
                    <button type="submit" class="btn-secondary">Save<span class="visually-hidden"> {{ $category->name }}</span></button>
                </div>
            </form>
            @if ($category->products_count === 0)
                <form method="post" action="{{ route('admin.categories.destroy', $category->id) }}" class="mt">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-danger">Delete<span class="visually-hidden"> {{ $category->name }}</span></button>
                </form>
            @endif
        </section>
    @empty
        <p>No categories.</p>
    @endforelse

    <h2>Add category</h2>
    <form method="post" action="{{ route('admin.categories.store') }}" class="stack">
        @csrf
        <x-field name="name" id="new-name" label="Name" maxlength="80" required />
        <x-field name="description" id="new-description" label="Description" maxlength="500" />
        <button type="submit">Add category</button>
    </form>
@endsection
