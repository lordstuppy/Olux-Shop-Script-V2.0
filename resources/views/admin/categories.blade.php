@extends('layouts.app')

@section('title', __('Categories - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Categories') }}</h1>
    @forelse ($categories as $category)
        <section class="card mt" aria-labelledby="cat-{{ $category->id }}">
            <h2 id="cat-{{ $category->id }}">{{ $category->name }} <span class="muted">(<span class="mono">{{ $category->slug }}</span>, {{ __(':count products', ['count' => $category->products_count]) }})</span></h2>
            <form method="post" action="{{ route('admin.categories.update', $category->id) }}" class="stack">
                @csrf
                @method('PUT')
                <x-field name="name" :id="'name-'.$category->id" :label="__('Name')" :value="$category->name" maxlength="80" required />
                <x-field optional name="description" :id="'desc-'.$category->id" :label="__('Description')" :value="$category->description" maxlength="500" />
                <div class="actions">
                    <button type="submit" class="btn-secondary">{{ __('Save') }}<span class="visually-hidden"> {{ $category->name }}</span></button>
                </div>
            </form>
            @if ($category->products_count === 0)
                <x-confirm class="mt" :action="route('admin.categories.destroy', $category->id)" method="DELETE" :label="__('Delete')" :context="$category->name"
                    :message="__('The category is removed from the shop. This cannot be undone.')" :confirm="__('Yes, delete category')" />
            @endif
        </section>
    @empty
        <p>{{ __('No categories.') }}</p>
    @endforelse

    <h2>{{ __('Add category') }}</h2>
    <form method="post" action="{{ route('admin.categories.store') }}" class="stack">
        @csrf
        <x-field name="name" id="new-name" :label="__('Name')" maxlength="80" required />
        <x-field optional name="description" id="new-description" :label="__('Description')" maxlength="500" />
        <button type="submit">{{ __('Add category') }}</button>
    </form>
@endsection
