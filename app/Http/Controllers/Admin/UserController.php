<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BalanceService;
use App\Services\UserService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

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
            'sessionCount' => DB::table('sessions')->where('user_id', $user->id)->count(),
        ]);
    }

    public function role(Request $request, User $user, UserService $users): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', Rule::enum(UserRole::class)]]);
        $users->setRole($user, UserRole::from($data['role']), $request->user());

        return back()->with('success', __(':email is now :role.', ['email' => $user->email, 'role' => mb_strtolower(UserRole::from($data['role'])->label())]));
    }

    public function revokeSessions(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $count = DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        $audit->log('user.sessions_revoked_by_staff', $user, ['count' => $count], $request->user());

        return back()->with('success', trans_choice('{1} Signed out :count session of :email.|[0,*] Signed out :count sessions of :email.', $count, ['email' => $user->email]));
    }

    public function adjustBalance(Request $request, User $user, BalanceService $balances, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(['credit', 'debit'])],
            'amount' => ['required', 'string', 'max:16'],
            'currency' => ['required', Rule::in(Money::supported())],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        try {
            $amount = Money::parseInput($data['amount'], $data['currency']);
        } catch (InvalidArgumentException) {
            throw new UserFacingException(__('Enter the amount as a number such as 5.00 in :currency.', ['currency' => $data['currency']]));
        }
        if ($amount <= 0) {
            throw new UserFacingException(__('The amount must be greater than zero.'));
        }

        $note = 'Adjustment by staff: '.$data['reason'];
        $data['direction'] === 'credit'
            ? $balances->credit($user, $amount, $data['currency'], 'admin_adjustment', $request->user(), $note)
            : $balances->debit($user, $amount, $data['currency'], 'admin_adjustment', $request->user(), $note);
        $audit->log('balance.adjusted', $user, [
            'direction' => $data['direction'],
            'amount' => Money::format($amount, $data['currency']),
            'reason' => $data['reason'],
        ], $request->user());

        $replace = [
            'amount' => Money::format($amount, $data['currency']),
            'email' => $user->email,
            'balance' => Money::format($user->fresh()->balance_minor, $user->fresh()->currency),
        ];

        return back()->with('success', $data['direction'] === 'credit'
            ? __('Credited :amount to :email. New balance: :balance.', $replace)
            : __('Debited :amount to :email. New balance: :balance.', $replace));
    }

    public function status(Request $request, User $user, UserService $users): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(UserStatus::class)]]);
        $users->setStatus($user, UserStatus::from($data['status']), $request->user());

        return back()->with('success', __(':email is now :status.', ['email' => $user->email, 'status' => mb_strtolower(UserStatus::from($data['status'])->label())]));
    }
}
