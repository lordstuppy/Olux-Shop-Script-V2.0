<?php

namespace App\Services;

use App\Exceptions\UserFacingException;
use App\Models\User;
use App\Support\KeyedHash;
use chillerlan\QRCode\QRCode;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor authentication (RFC 6238, 30-second steps, 6 digits),
 * compatible with common authenticator apps. Each code is accepted once.
 */
class TwoFactorService
{
    private const RECOVERY_CODES = 8;

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly AuditLogger $audit,
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
        // Passing 0 (not null) makes the library return the matched time step
        // instead of true, so the setup code cannot be replayed at sign-in.
        $step = $this->google2fa->verifyKeyNewer($secret, $this->normalize($code), 0, 1);
        if (! is_int($step)) {
            throw new UserFacingException('That code is not valid. Check the time on your phone and enter the current 6-digit code.');
        }

        $codes = $this->newRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => array_map([$this, 'hashRecoveryCode'], $codes),
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => (int) $step,
        ])->save();
        $this->audit->log('user.two_factor_enabled', $user, [], $user);

        return $codes;
    }

    public function disable(User $user, string $code): void
    {
        if (! $this->verify($user, $code)) {
            throw new UserFacingException('That code is not valid. Enter a current authenticator code or an unused recovery code.');
        }
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
        $this->audit->log('user.two_factor_disabled', $user, [], $user);
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map([$this, 'hashRecoveryCode'], $codes)])->save();
        $this->audit->log('user.two_factor_recovery_regenerated', $user, [], $user);

        return $codes;
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
        if (preg_match('/^\d{6}$/', $code)) {
            $step = $this->google2fa->verifyKeyNewer($user->two_factor_secret, $code, $user->two_factor_last_step ?? 0, 1);
            if (! is_int($step)) {
                return false;
            }
            $user->forceFill(['two_factor_last_step' => (int) $step])->save();

            return true;
        }

        $candidates = KeyedHash::candidates($code);
        $remaining = $user->two_factor_recovery_codes ?? [];
        foreach ($remaining as $i => $stored) {
            if (collect($candidates)->contains(fn ($hash) => hash_equals($stored, $hash))) {
                unset($remaining[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($remaining)])->save();
                $this->audit->log('user.two_factor_recovery_used', $user, ['remaining' => count($remaining)], $user);

                return true;
            }
        }

        return false;
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
