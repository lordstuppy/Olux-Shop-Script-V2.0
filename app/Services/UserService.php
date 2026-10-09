<?php

namespace App\Services;

use App\Enums\SellerProfileStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\UserFacingException;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Registration, login, password reset and seller onboarding.
 */
class UserService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function register(string $name, string $email, string $password): User
    {
        $user = User::create([
            'name' => $name,
            'email' => Str::lower($email),
            'password_hash' => $password,
        ]);
        // Server-side defaults; never taken from the request.
        $user->forceFill([
            'role' => UserRole::Buyer,
            'status' => UserStatus::Active,
            'currency' => config('shop.default_currency'),
        ])->save();

        $this->audit->log('user.registered', $user, [], $user);
        Log::info('User {user_id} registered', ['user_id' => $user->id]);

        return $user;
    }

    /**
     * @throws UserFacingException with a specific reason when login fails
     */
    public function login(string $email, string $password, bool $remember): User
    {
        if (! Auth::attempt(['email' => Str::lower($email), 'password' => $password], $remember)) {
            Log::notice('Failed login for email hash {email_hash}', ['email_hash' => hash('sha256', Str::lower($email))]);
            throw new UserFacingException('Login failed: the email or password is incorrect.');
        }

        /** @var User $user */
        $user = Auth::user();
        if (! $user->isActive()) {
            Auth::logout();
            throw new UserFacingException('This account is suspended. Contact '.config('shop.support_email').' for help.');
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->log('user.login', $user, [], $user);

        return $user;
    }

    /** Always reports success so the form cannot be used to discover accounts. */
    public function sendPasswordResetLink(string $email): void
    {
        $status = Password::sendResetLink(['email' => Str::lower($email)]);
        Log::info('Password reset requested; broker status {status}', ['status' => $status]);
    }

    public function resetPassword(string $email, string $token, string $password): void
    {
        $status = Password::reset(
            ['email' => Str::lower($email), 'token' => $token, 'password' => $password],
            function (User $user, string $password) {
                $user->forceFill([
                    'password_hash' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
                // Sign out other sessions that used the old password.
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $this->audit->log('user.password_reset', $user, [], $user);
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new UserFacingException(match ($status) {
                Password::INVALID_TOKEN => 'This password reset link is invalid or has expired. Request a new one.',
                Password::RESET_THROTTLED => 'Too many reset attempts. Wait a minute and try again.',
                default => 'The password could not be reset. Request a new reset link.',
            });
        }
    }

    public function applyAsSeller(User $user, array $data): SellerProfile
    {
        if ($user->isSeller() || $user->isAdmin()) {
            throw new UserFacingException('Your account can already sell.');
        }

        $profile = $user->sellerProfile;
        if ($profile?->status === SellerProfileStatus::Pending) {
            throw new UserFacingException('Your seller application is already waiting for review.');
        }

        $profile ??= new SellerProfile(['user_id' => $user->id]);
        $profile->fill([
            'display_name' => $data['display_name'],
            'payout_currency' => $data['payout_currency'],
            'payout_address' => $data['payout_address'],
            'about' => $data['about'] ?? null,
            'status' => SellerProfileStatus::Pending,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_note' => null,
        ]);
        $profile->save();
        $this->audit->log('seller.applied', $profile, [], $user);

        return $profile;
    }

    public function approveSeller(SellerProfile $profile, User $admin, ?int $commissionBps = null): void
    {
        DB::transaction(function () use ($profile, $admin, $commissionBps) {
            $profile->forceFill([
                'status' => SellerProfileStatus::Approved,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'commission_bps' => $commissionBps,
            ])->save();
            $user = $profile->user;
            if ($user->role === UserRole::Buyer) {
                $user->forceFill(['role' => UserRole::Seller])->save();
            }
            $this->audit->log('seller.approved', $profile, ['commission_bps' => $commissionBps], $admin);
        });
    }

    public function rejectSeller(SellerProfile $profile, User $admin, string $note): void
    {
        $profile->forceFill([
            'status' => SellerProfileStatus::Rejected,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();
        $this->audit->log('seller.rejected', $profile, ['note' => $note], $admin);
    }

    public function setRole(User $user, UserRole $role, User $admin): void
    {
        if ($user->id === $admin->id) {
            throw new UserFacingException('You cannot change your own role.');
        }
        $old = $user->role;
        $user->forceFill(['role' => $role])->save();
        $this->audit->log('user.role_changed', $user, ['from' => $old->value, 'to' => $role->value], $admin);
    }

    public function setStatus(User $user, UserStatus $status, User $admin): void
    {
        if ($user->id === $admin->id) {
            throw new UserFacingException('You cannot suspend your own account.');
        }
        $user->forceFill(['status' => $status])->save();
        if ($status === UserStatus::Suspended) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
        $this->audit->log('user.status_changed', $user, ['to' => $status->value], $admin);
    }
}
