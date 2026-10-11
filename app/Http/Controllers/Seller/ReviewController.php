<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Services\ReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Reviews of the seller's products, with a public reply to each. */
class ReviewController extends Controller
{
    public function index(Request $request): View
    {
        $reviews = ProductReview::query()->with(['product', 'user'])
            ->whereIn('product_id', $request->user()->products()->select('id'))
            ->orderByRaw('seller_replied_at IS NOT NULL')->latest('id')->paginate(20);

        return view('seller.reviews', ['reviews' => $reviews]);
    }

    public function reply(Request $request, ProductReview $review, ReviewService $reviews): RedirectResponse
    {
        $data = $request->validate(['reply' => ['nullable', 'string', 'max:2000']]);
        $reviews->reply($review, $request->user(), (string) ($data['reply'] ?? ''));

        return redirect()->route('seller.reviews')->with('success', trim((string) ($data['reply'] ?? '')) === ''
            ? __('Your reply was removed.') : __('Your reply is published under the review.'));
    }
}
