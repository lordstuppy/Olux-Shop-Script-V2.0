<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Services\ReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReviewController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['visible', 'hidden'])]]);
        $query = ProductReview::with(['product', 'user'])->latest('id');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return view('admin.reviews', ['reviews' => $query->paginate(30)->withQueryString(), 'filters' => $data]);
    }

    public function status(Request $request, ProductReview $review, ReviewService $reviews): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['visible', 'hidden'])]]);
        $reviews->moderate($review, $data['status'], $request->user());

        return back()->with('success', "Review #{$review->id} is now {$data['status']}.");
    }
}
