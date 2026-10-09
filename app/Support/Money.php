<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Helpers for integer minor-unit money. Floats are never used.
 */
final class Money
{
    public static function exponent(string $currency): int
    {
        $currencies = config('shop.currencies');
        if (! array_key_exists($currency, $currencies)) {
            throw new InvalidArgumentException("Unsupported currency {$currency}.");
        }

        return (int) $currencies[$currency];
    }

    public static function isSupported(string $currency): bool
    {
        return array_key_exists($currency, config('shop.currencies'));
    }

    /** @return list<string> */
    public static function supported(): array
    {
        return array_keys(config('shop.currencies'));
    }

    /** 2500, "EUR" => "25.00" */
    public static function toDecimal(int $minor, string $currency): string
    {
        return (string) BigDecimal::ofUnscaledValue($minor, self::exponent($currency));
    }

    /** 2500, "EUR" => "25.00 EUR" */
    public static function format(int $minor, string $currency): string
    {
        return self::toDecimal($minor, $currency).' '.$currency;
    }

    /**
     * Parses a decimal string such as "24.90" or "24.9000001" into minor units.
     * Digits beyond the currency exponent are truncated (rounded towards zero),
     * so a received amount is never overstated.
     */
    public static function parseReceived(string $value, string $currency): int
    {
        $value = trim($value);
        if (! preg_match('/^\d{1,15}(\.\d{1,18})?$/', $value)) {
            throw new InvalidArgumentException("Invalid decimal amount \"{$value}\".");
        }

        return BigDecimal::of($value)->toScale(self::exponent($currency), RoundingMode::Down)
            ->getUnscaledValue()->toInt();
    }

    /**
     * Parses user input such as "25" or "25.00" into minor units. Rejects
     * more decimals than the currency allows instead of rounding them.
     */
    public static function parseInput(string $value, string $currency): int
    {
        $value = trim($value);
        $exponent = self::exponent($currency);
        $pattern = $exponent > 0 ? '/^\d{1,9}(\.\d{1,'.$exponent.'})?$/' : '/^\d{1,9}$/';
        if (! preg_match($pattern, $value)) {
            throw new InvalidArgumentException("Invalid amount \"{$value}\" for {$currency}.");
        }

        return BigDecimal::of($value)->toScale($exponent)->getUnscaledValue()->toInt();
    }

    /** Applies basis points with half-up rounding: (1999, 1000) => 200. */
    public static function applyBps(int $minor, int $bps): int
    {
        return BigInteger::of($minor)->multipliedBy($bps)
            ->toBigDecimal()->dividedBy(10000, 0, RoundingMode::HalfUp)->toInt();
    }

    /**
     * Splits $total across $weights proportionally using the largest
     * remainder method. The result always sums to exactly $total.
     *
     * @param  array<int|string, int>  $weights
     * @return array<int|string, int>
     */
    public static function allocate(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        if ($total === 0 || $sum === 0) {
            return array_map(fn () => 0, $weights);
        }

        $shares = [];
        $remainders = [];
        $allocated = 0;
        foreach ($weights as $key => $weight) {
            [$quotient, $remainder] = BigInteger::of($total)->multipliedBy($weight)->quotientAndRemainder($sum);
            $shares[$key] = $quotient->toInt();
            $remainders[$key] = $remainder->toInt();
            $allocated += $shares[$key];
        }

        $left = $total - $allocated;
        arsort($remainders);
        foreach (array_keys($remainders) as $key) {
            if ($left <= 0) {
                break;
            }
            $shares[$key]++;
            $left--;
        }

        return $shares;
    }
}
