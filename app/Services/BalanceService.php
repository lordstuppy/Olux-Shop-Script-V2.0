<?php

namespace App\Services;

use App\Exceptions\UserFacingException;
use App\Models\BalanceTransaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only code path that changes users.balance_minor. Every change locks the
 * user row and appends a balance_transactions entry.
 *
 * Currency policy: a balance holds one currency. A credit in another currency
 * is accepted only while the balance is zero, in which case the balance
 * switches to the credited currency. Balances are never converted implicitly.
 */
class BalanceService
{
    public function credit(User $user, int $amountMinor, string $currency, string $type, ?Model $reference = null, ?string $note = null): BalanceTransaction
    {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amountMinor, $currency, $type, $reference, $note) {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->currency !== $currency) {
                if ($locked->balance_minor !== 0) {
                    throw new UserFacingException(__(
                        'Your balance is held in :balance_currency, so :amount cannot be added to it. Contact support to arrange a conversion.',
                        ['balance_currency' => $locked->currency, 'amount' => Money::format($amountMinor, $currency)],
                    ));
                }
                $locked->currency = $currency;
            }

            $locked->balance_minor += $amountMinor;
            $locked->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            return $this->record($locked, $amountMinor, $currency, $type, $reference, $note);
        });
    }

    public function debit(User $user, int $amountMinor, string $currency, string $type, ?Model $reference = null, ?string $note = null): BalanceTransaction
    {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('Debit amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amountMinor, $currency, $type, $reference, $note) {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->currency !== $currency) {
                throw new UserFacingException(__(
                    'Your balance is held in :balance_currency but this order is in :currency. Pay with crypto instead, or switch the shop currency to :balance_currency.',
                    ['balance_currency' => $locked->currency, 'currency' => $currency],
                ));
            }
            if ($locked->balance_minor < $amountMinor) {
                throw new UserFacingException(__(
                    'Insufficient balance. Order total :total, available :available.',
                    ['total' => Money::format($amountMinor, $currency), 'available' => Money::format($locked->balance_minor, $currency)],
                ));
            }

            $locked->balance_minor -= $amountMinor;
            $locked->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            return $this->record($locked, -$amountMinor, $currency, $type, $reference, $note);
        });
    }

    private function record(User $user, int $signedAmount, string $currency, string $type, ?Model $reference, ?string $note): BalanceTransaction
    {
        return BalanceTransaction::create([
            'user_id' => $user->getKey(),
            'amount_minor' => $signedAmount,
            'currency' => $currency,
            'type' => $type,
            'reference_type' => $reference ? class_basename($reference) : null,
            'reference_id' => $reference?->getKey(),
            'balance_after_minor' => $user->balance_minor,
            'note' => $note,
        ]);
    }
}
