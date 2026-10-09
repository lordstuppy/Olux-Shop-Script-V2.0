<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SellerProfile;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SellerController extends Controller
{
    public function index(): View
    {
        return view('admin.sellers', [
            'profiles' => SellerProfile::with('user')->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")->latest('id')->paginate(30),
        ]);
    }

    public function approve(Request $request, SellerProfile $profile, UserService $users): RedirectResponse
    {
        $data = $request->validate(['commission_bps' => ['nullable', 'integer', 'min:0', 'max:10000']]);
        $users->approveSeller($profile, $request->user(), isset($data['commission_bps']) ? (int) $data['commission_bps'] : null);

        return back()->with('success', __('Approved :name as a seller.', ['name' => $profile->display_name]));
    }

    public function reject(Request $request, SellerProfile $profile, UserService $users): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $users->rejectSeller($profile, $request->user(), $data['note']);

        return back()->with('success', __('Rejected the application from :name.', ['name' => $profile->display_name]));
    }
}
