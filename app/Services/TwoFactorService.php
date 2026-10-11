<?php

namespace App\Services;

use App\Exceptions\UserFacingException;
use App\Mail\AccountSecurityMail;
use App\Models\User;
use App\Support\KeyedHash;
use chillerlan\QRCode\QRCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor authentication (RFC 6238, 30-second steps, 6 digits),
 * compatible with common authenticator apps. Each code is accepted once.
 */
class TwoFactorService
{
    private const RECOVERY_CODES = 8;

    /** Codes one account may try per hour, at sign-in and in account settings together (about 240 guesses a day). */
    public const CODES_PER_HOUR = 10;

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret);
    }

    /** QR code as a data URI (SVG), rendered on the server. */
    public function qrDataUri(User $user, string $secret): string
    {
        return (new QRCode)->render($this->otpauthUrl($user, $secret));
    }

    /**
     * @return list<string> plain recovery codes, shown to the user once
     */
    public function enable(User $user, string $secret, string $code): array
    {
        // Replacing an active setup goes through "turn off" (which needs a current code) first.
        if ($user->fresh()?->hasTwoFactor()) {
            throw new UserFacingException(__('Two-factor authentication is already on.'));
        }
        // Passing 0 (not null) makes the library return the matched time step
        // instead of true, so the setup code cannot be replayed at sign-in.
        $step = $this->google2fa->verifyKeyNewer($secret, $this->normalize($code), 0, 1);
        if (! is_int($step)) {
            throw new UserFacingException(__('That code is not valid. Check the time on your phone and enter the current 6-digit code.'));
        }

        $codes = $this->newRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => array_map([$this, 'hashRecoveryCode'], $codes),
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => (int) $step,
        ])->save();
        $this->audit->log('user.two_factor_enabled', $user, [], $user);
        $this->notifier->send($user, new AccountSecurityMail($user, 'two_factor_enabled'));

        return $codes;
    }

    public function disable(User $user, string $code): void
    {
        if (! $this->verifyLimited($user, $code)) {
            throw new UserFacingException(__('That code is not valid. Enter a current authenticator code or an unused recovery code.'));
        }
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
        $this->audit->log('user.two_factor_disabled', $user, [], $user);
        $this->notifier->send($user, new AccountSecurityMail($user, 'two_factor_disabled'));
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user, string $code): array
    {
        // Lasting bypass codes need proof of the second factor, like turning it off.
        if (! $this->verifyLimited($user, $code)) {
            throw new UserFacingException(__('That code is not valid. Enter a current authenticator code or an unused recovery code.'));
        }
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map([$this, 'hashRecoveryCode'], $codes)])->save();
        $this->audit->log('user.two_factor_recovery_regenerated', $user, [], $user);
        $this->notifier->send($user, new AccountSecurityMail($user, 'recovery_codes_regenerated'));

        return $codes;
    }

    /**
     * verify() with a per-account budget: 5 codes a minute and CODES_PER_HOUR an
     * hour, counted before the check so parallel requests cannot slip under
     * it, whatever the IP. The owner is emailed when the hourly budget runs out.
     */
    public function verifyLimited(User $user, string $code): bool
    {
        $key = 'two-factor:'.$user->id;
        $hourKey = 'two-factor-hour:'.$user->id;
        $perMinute = RateLimiter::hit($key, 60);
        $perHour = RateLimiter::hit($hourKey, 3600);
        if ($perHour === self::CODES_PER_HOUR + 1) {
            Log::warning('Two-factor codes paused for user {user_id} after {count} codes in an hour', ['user_id' => $user->id, 'count' => self::CODES_PER_HOUR]);
            $this->notifier->send($user, new AccountSecurityMail($user, 'two_factor_locked'));
        }
        if ($perMinute > 5 || $perHour > self::CODES_PER_HOUR) {
            $wait = max(RateLimiter::availableIn($key), $perHour > self::CODES_PER_HOUR ? RateLimiter::availableIn($hourKey) : 0);
            throw new UserFacingException(__('Too many incorrect codes. Wait :seconds seconds and try again.', ['seconds' => $wait]));
        }
        if (! $this->verify($user, $code)) {
            return false;
        }
        RateLimiter::clear($key);
        RateLimiter::clear($hourKey);

        return true;
    }

    /**
     * Accepts a current TOTP code (never the same time step twice) or an
     * unused recovery code, which is then consumed.
     */
    public function verify(User $user, string $input): bool
    {
        if (! $user->hasTwoFactor()) {
            return false;
        }

        $code = $this->normalize($input);

        // Under a row lock, so two parallel requests cannot both use the same
        // time step or the same recovery code.
        return DB::transaction(function () use ($user, $code) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($locked === null || ! $locked->hasTwoFactor()) {
                return false;
            }

            if (preg_match('/^\d{6}$/', $code)) {
                $step = $this->google2fa->verifyKeyNewer($locked->two_factor_secret, $code, $locked->two_factor_last_step ?? 0, 1);
                if (! is_int($step)) {
                    return false;
                }
                $user->forceFill(['two_factor_last_step' => (int) $step])->save();

                return true;
            }

            $candidates = KeyedHash::candidates($code);
            $remaining = $locked->two_factor_recovery_codes ?? [];
            foreach ($remaining as $i => $stored) {
                if (collect($candidates)->contains(fn ($hash) => hash_equals($stored, $hash))) {
                    unset($remaining[$i]);
                    $user->forceFill(['two_factor_recovery_codes' => array_values($remaining)])->save();
                    $this->audit->log('user.two_factor_recovery_used', $user, ['remaining' => count($remaining)], $user);

                    return true;
                }
            }

            return false;
        });
    }

    private function normalize(string $input): string
    {
        return strtoupper(preg_replace('/[\s-]+/', '', $input) ?? '');
    }

    private function hashRecoveryCode(string $code): string
    {
        return KeyedHash::make($this->normalize($code));
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = Str::upper(Str::random(10));
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }

        return $codes;
    }
}
