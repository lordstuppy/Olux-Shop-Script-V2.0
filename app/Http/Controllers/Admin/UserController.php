<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
        ]);
        $query = User::query()->latest('id');
        if (! empty($data['q'])) {
            $like = '%'.addcslashes($data['q'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('email', 'ILIKE', $like)->orWhere('name', 'ILIKE', $like));
        }
        if (! empty($data['role'])) {
            $query->where('role', $data['role']);
        }

        return view('admin.users.index', ['users' => $query->paginate(30)->withQueryString(), 'filters' => $data]);
    }

    public function show(User $user): View
    {
        $user->load('sellerProfile');

        return view('admin.users.show', [
            'user' => $user,
            'orders' => $user->orders()->latest('id')->limit(20)->get(),
            'transactions' => $user->balanceTransactions()->latest('id')->limit(20)->get(),
        ]);
    }

    public function role(Request $request, User $user, UserService $users): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', Rule::enum(UserRole::class)]]);
        $users->setRole($user, UserRole::from($data['role']), $request->user());

        return back()->with('success', "{$user->email} is now {$data['role']}.");
    }

    public function status(Request $request, User $user, UserService $users): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(UserStatus::class)]]);
        $users->setStatus($user, UserStatus::from($data['status']), $request->user());

        return back()->with('success', "{$user->email} is now {$data['status']}.");
    }
}
