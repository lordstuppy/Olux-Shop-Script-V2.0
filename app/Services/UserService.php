<?php

namespace App\Services;

use App\Enums\SellerProfileStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\UserFacingException;
use App\Mail\EmailChangeConfirmMail;
use App\Mail\EmailChangeNoticeMail;
use App\Mail\NewDeviceLoginMail;
use App\Mail\SellerApplicationMail;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Registration, login, password reset and seller onboarding.
 */
class UserService
{
    public const DEVICE_COOKIE = 'shop_device';

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

        $user->sendEmailVerificationNotification();
        $this->audit->log('user.registered', $user, [], $user);
        Log::info('User {user_id} registered', ['user_id' => $user->id]);

        return $user;
    }

    /**
     * Checks credentials without signing in. The caller either completes the
     * login (completeLogin) or, for accounts with two-factor authentication,
     * asks for a code first.
     *
     * @throws UserFacingException with a specific reason when the check fails
     */
    public function checkCredentials(string $email, string $password): User
    {
        $provider = Auth::getProvider();
        $credentials = ['email' => Str::lower($email), 'password' => $password];
        /** @var User|null $user */
        $user = $provider->retrieveByCredentials($credentials);

        if ($user === null || ! $provider->validateCredentials($user, $credentials)) {
            Log::notice('Failed login for email hash {email_hash}', ['email_hash' => hash('sha256', Str::lower($email))]);
            throw new UserFacingException(__('Login failed: the email or password is incorrect.'));
        }
        if (! $user->isActive()) {
            throw new UserFacingException(__('This account is suspended. Contact :email for help.', ['email' => config('shop.support_email')]));
        }
        $provider->rehashPasswordIfRequired($user, $credentials);

        return $user;
    }

    /**
     * Signs the user in and records the device. A login from a device the
     * account has not used before triggers an email to the account owner.
     */
    public function completeLogin(User $user, bool $remember, Request $request): void
    {
        Auth::login($user, $remember);
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->log('user.login', $user, ['two_factor' => $user->hasTwoFactor()], $user);
        $this->rememberDevice($user, $request);
    }

    private function rememberDevice(User $user, Request $request): void
    {
        $cookie = (string) $request->cookie(self::DEVICE_COOKIE, '');
        if (! preg_match('/^[a-f0-9]{64}$/', $cookie)) {
            $cookie = bin2hex(random_bytes(32));
        }
        Cookie::queue(Cookie::forever(self::DEVICE_COOKIE, $cookie, '/', null, (bool) config('session.secure'), true, false, 'lax'));

        $hash = hash('sha256', $cookie);
        $known = UserDevice::query()->where('user_id', $user->id)->where('device_hash', $hash)->first();
        if ($known !== null) {
            $known->forceFill(['last_seen_at' => now(), 'ip' => $request->ip()])->save();

            return;
        }

        $firstDevice = ! UserDevice::query()->where('user_id', $user->id)->exists();
        $device = UserDevice::create([
            'user_id' => $user->id,
            'device_hash' => $hash,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'ip' => $request->ip(),
            'last_seen_at' => now(),
        ]);
        if (! $firstDevice) {
            Mail::to($user)->queue(new NewDeviceLoginMail($user, $device));
        }
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
                Password::INVALID_TOKEN => __('This password reset link is invalid or has expired. Request a new one.'),
                Password::RESET_THROTTLED => __('Too many reset attempts. Wait a minute and try again.'),
                default => __('The password could not be reset. Request a new reset link.'),
            });
        }
    }

    /**
     * Starts an email change. The new address must confirm through a link;
     * the old address is told about the request.
     */
    public function requestEmailChange(User $user, string $newEmail): void
    {
        $newEmail = Str::lower(trim($newEmail));
        if ($newEmail === $user->email) {
            throw new UserFacingException(__('That is already your email address.'));
        }
        if (User::query()->where('email', $newEmail)->exists()) {
            throw new UserFacingException(__('Another account already uses that email address.'));
        }

        $token = Str::random(48);
        $user->forceFill([
            'pending_email' => $newEmail,
            'email_change_token_hash' => hash('sha256', $token),
            'email_change_expires_at' => now()->addDay(),
        ])->save();

        Mail::to($newEmail)->queue(new EmailChangeConfirmMail($user, route('account.email.confirm', $token)));
        Mail::to($user->email)->queue(new EmailChangeNoticeMail($user, $newEmail));
        $this->audit->log('user.email_change_requested', $user, ['to_hash' => hash('sha256', $newEmail)], $user);
    }

    public function confirmEmailChange(User $user, string $token): void
    {
        if ($user->email_change_token_hash === null || ! hash_equals($user->email_change_token_hash, hash('sha256', $token))
            || $user->email_change_expires_at === null || $user->email_change_expires_at->isPast()) {
            throw new UserFacingException(__('This confirmation link is invalid or has expired. Request the change again from your account settings.'));
        }
        if (User::query()->where('email', $user->pending_email)->whereKeyNot($user->id)->exists()) {
            throw new UserFacingException(__('Another account now uses that email address.'));
        }

        $old = $user->email;
        $user->forceFill([
            'email' => $user->pending_email,
            'email_verified_at' => now(),
            'pending_email' => null,
            'email_change_token_hash' => null,
            'email_change_expires_at' => null,
        ])->save();
        $this->audit->log('user.email_changed', $user, ['from_hash' => hash('sha256', $old)], $user);
    }

    public function applyAsSeller(User $user, array $data): SellerProfile
    {
        if ($user->isSeller() || $user->isStaff()) {
            throw new UserFacingException(__('Your account can already sell.'));
        }

        $profile = $user->sellerProfile;
        if ($profile?->status === SellerProfileStatus::Pending) {
            throw new UserFacingException(__('Your seller application is already waiting for review.'));
        }

        $profile ??= new SellerProfile(['user_id' => $user->id]);
        $profile->fill([
            'display_name' => $data['display_name'],
            'payout_currency' => $data['payout_currency'],
            'payout_address' => $data['payout_address'],
            'payout_crypto' => $data['payout_crypto'] ?? null,
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
            Mail::to($profile->user)->queue((new SellerApplicationMail($profile))->afterCommit());
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
        Mail::to($profile->user)->queue(new SellerApplicationMail($profile));
    }

    public function setRole(User $user, UserRole $role, User $admin): void
    {
        if ($user->id === $admin->id) {
            throw new UserFacingException(__('You cannot change your own role.'));
        }
        $old = $user->role;
        $user->forceFill(['role' => $role])->save();
        $this->audit->log('user.role_changed', $user, ['from' => $old->value, 'to' => $role->value], $admin);
    }

    public function setStatus(User $user, UserStatus $status, User $admin): void
    {
        if ($user->id === $admin->id) {
            throw new UserFacingException(__('You cannot suspend your own account.'));
        }
        if ($user->role->isStaff() && $admin->role !== UserRole::Admin) {
            throw new UserFacingException(__('Only a super admin can change the status of a staff account.'));
        }
        $user->forceFill(['status' => $status])->save();
        if ($status === UserStatus::Suspended) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
        $this->audit->log('user.status_changed', $user, ['to' => $status->value], $admin);
    }
}
