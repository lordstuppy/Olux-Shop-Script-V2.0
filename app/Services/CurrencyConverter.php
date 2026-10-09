<?php

namespace App\Services;

use App\Exceptions\UserFacingException;
use App\Models\ExchangeRate;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Converts list prices into the order currency using admin-maintained rates.
 *
 * Policy (see docs/CURRENCY_POLICY.md):
 *  - Rates are explicit per direction; an inverse rate is never inferred.
 *  - Conversion happens per unit price and is rounded half-up to the target
 *    currency's minor unit; line totals are unit price times quantity.
 *  - The rate used is stored on each order item, so later rate changes never
 *    alter an existing order.
 */
class CurrencyConverter
{
    /** @var array<string, string|null> */
    private array $cache = [];

    public function rate(string $from, string $to): ?string
    {
        if ($from === $to) {
            return '1';
        }

        $key = $from.'>'.$to;
        if (! array_key_exists($key, $this->cache)) {
            $rate = ExchangeRate::query()->where('base', $from)->where('quote', $to)->value('rate');
            $this->cache[$key] = $rate === null ? null : (string) $rate;
        }

        return $this->cache[$key];
    }

    /**
     * @return array{0: int, 1: string} converted minor amount and the rate used
     */
    public function convert(int $minor, string $from, string $to): array
    {
        $rate = $this->rate($from, $to);
        if ($rate === null) {
            throw new UserFacingException(__('Prices in :from cannot be shown in :to because no exchange rate is configured. Switch the shop currency to :from.', ['from' => $from, 'to' => $to]));
        }
        if ($from === $to) {
            return [$minor, '1'];
        }

        $amount = BigDecimal::ofUnscaledValue($minor, Money::exponent($from))
            ->multipliedBy($rate)
            ->toScale(Money::exponent($to), RoundingMode::HalfUp);

        return [$amount->getUnscaledValue()->toInt(), $rate];
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
