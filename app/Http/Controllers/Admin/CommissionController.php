<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SellerProfileStatus;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Services\CommissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * One place for every commission level (super admin only). The global
 * default lives in Settings; this page manages the category, seller and
 * product levels and shows which level applies.
 */
class CommissionController extends Controller
{
    public function __construct(private readonly CommissionService $commission) {}

    public function index(): View
    {
        return view('admin.commission', [
            'default' => (int) config('shop.commission_bps'),
            'categories' => Category::query()->orderBy('name')->get(),
            'sellers' => SellerProfile::query()->with('user')->where('status', SellerProfileStatus::Approved->value)->orderBy('display_name')->get(),
            'products' => Product::query()->with(['seller.sellerProfile', 'category'])->whereNotNull('commission_bps')->orderBy('title')->get(),
        ]);
    }

    public function updateCategory(Request $request, Category $category): RedirectResponse
    {
        $this->commission->set($category, $this->rate($request), $request->user());

        return back()->with('success', $this->message($category->name, $category->commission_bps));
    }

    public function updateSeller(Request $request, SellerProfile $profile): RedirectResponse
    {
        $this->commission->set($profile, $this->rate($request), $request->user());

        return back()->with('success', $this->message($profile->display_name, $profile->commission_bps));
    }

    public function updateProduct(Request $request): RedirectResponse
    {
        $data = $request->validate(['product_id' => ['required', 'integer']]);
        $product = Product::query()->find($data['product_id']);
        if ($product === null) {
            throw new UserFacingException(__('There is no product #:id.', ['id' => $data['product_id']]));
        }
        $this->commission->set($product, $this->rate($request), $request->user());

        return back()->with('success', $this->message($product->title, $product->commission_bps));
    }

    /** Percent with up to two decimals ("12.5"), or empty to clear the level. */
    private function rate(Request $request): ?int
    {
        $data = $request->validate(['commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,2})?$/']]);
        $value = $data['commission_percent'] ?? null;

        return $value === null || $value === '' ? null : (int) round(((float) $value) * 100);
    }

    private function message(string $name, ?int $bps): string
    {
        return $bps === null
            ? __('Commission for ":name" cleared; the next level applies.', ['name' => $name])
            : __('Commission for ":name" set to :percent.', ['name' => $name, 'percent' => CommissionService::percent($bps)]);
    }
}
