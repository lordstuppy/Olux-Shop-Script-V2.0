@extends('layouts.app')

@section('title', __('Reviews - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Reviews') }}</h1>
    <form class="filters" method="get">
        <x-select name="status" :label="__('Status')" :options="['visible' => __('Visible'), 'hidden' => __('Hidden')]" :value="$filters['status'] ?? ''" :placeholder="__('Any status')" />
        <button type="submit">{{ __('Filter') }}</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">{{ __('Product') }}</th><th scope="col">{{ __('Buyer') }}</th><th scope="col">{{ __('Rating') }}</th><th scope="col">{{ __('Review') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Action') }}</th></tr></thead>
            <tbody>
                @forelse ($reviews as $review)
                    <tr>
                        <td>{{ $review->product->title }}</td>
                        <td>{{ $review->user->email }}</td>
                        <td>{{ $review->rating }} / 5</td>
                        <td><strong>{{ $review->title }}</strong><br>{{ \Illuminate\Support\Str::limit($review->body, 200) }}</td>
                        <td><x-status :value="$review->status === 'visible' ? 'active' : 'disabled'" /></td>
                        <td>
                            <form method="post" action="{{ route('admin.reviews.status', $review) }}">
                                @csrf
                                <input type="hidden" name="status" value="{{ $review->status === 'visible' ? 'hidden' : 'visible' }}">
                                <button type="submit" class="btn-secondary">{{ $review->status === 'visible' ? __('Hide') : __('Show') }}<span class="visually-hidden"> {{ __('review :id', ['id' => $review->id]) }}</span></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">{{ __('No reviews.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $reviews->links() }}
@endsection
