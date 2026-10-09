@extends('layouts.app')

@section('title', 'Reviews - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Reviews</h1>
    <form class="filters" method="get">
        <x-select name="status" label="Status" :options="['visible' => 'Visible', 'hidden' => 'Hidden']" :value="$filters['status'] ?? ''" placeholder="Any status" />
        <button type="submit">Filter</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Product</th><th scope="col">Buyer</th><th scope="col">Rating</th><th scope="col">Review</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
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
                                <button type="submit" class="btn-secondary">{{ $review->status === 'visible' ? 'Hide' : 'Show' }}<span class="visually-hidden"> review {{ $review->id }}</span></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">No reviews.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $reviews->links() }}
@endsection
