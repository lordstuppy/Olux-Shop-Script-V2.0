<?php

namespace App\Services;

use App\Exceptions\UserFacingException;
use App\Models\GiftCard;
use App\Models\User;
use App\Support\KeyedHash;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gift balances. Codes are stored only as an HMAC, so a database leak does
 * not reveal redeemable codes. The plain code is shown once at creation.
 */
class GiftCardService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly BalanceService $balances,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{0: GiftCard, 1: string} the card and its plain code
     */
    public function create(int $amountMinor, string $currency, User $admin, ?Carbon $expiresAt = null): array
    {
        if (! Money::isSupported($currency) || $amountMinor <= 0) {
            throw new UserFacingException(__('Gift cards need a positive amount in a supported currency.'));
        }

        $code = $this->generateCode();
        $card = GiftCard::create([
            'code_hash' => $this->hash($code),
            'code_last4' => substr(str_replace('-', '', $code), -4),
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'created_by' => $admin->id,
            'expires_at' => $expiresAt,
        ]);
        $this->audit->log('gift_card.created', $card, ['amount' => Money::format($amountMinor, $currency)], $admin);

        return [$card, $code];
    }

    public function redeem(User $user, string $code): GiftCard
    {
        return DB::transaction(function () use ($user, $code) {
            $card = GiftCard::query()->whereIn('code_hash', KeyedHash::candidates($this->normalize($code)))->lockForUpdate()->first();
            if ($card === null) {
                throw new UserFacingException(__('Gift card code not recognised. Check the code and try again.'));
            }
            if ($card->redeemed_at !== null) {
                throw new UserFacingException(__('This gift card was already redeemed on :date.', ['date' => $card->redeemed_at->toDateString()]));
            }
            if ($card->expires_at !== null && $card->expires_at->isPast()) {
                throw new UserFacingException(__('This gift card expired on :date.', ['date' => $card->expires_at->toDateString()]));
            }

            $this->balances->credit($user, $card->amount_minor, $card->currency, 'gift_card', $card, 'Gift card ending '.$card->code_last4);
            $card->redeemed_by = $user->id;
            $card->redeemed_at = now();
            $card->save();
            $this->audit->log('gift_card.redeemed', $card, ['amount' => Money::format($card->amount_minor, $card->currency)], $user);

            return $card;
        });
    }

    private function hash(string $code): string
    {
        return KeyedHash::make($this->normalize($code));
    }

    private function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    private function generateCode(): string
    {
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $group = '';
            for ($i = 0; $i < 4; $i++) {
                $group .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $group;
        }

        return implode('-', $groups);
    }
}
