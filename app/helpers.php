<?php

use App\Support\Money;

if (! function_exists('money')) {
    /** Formats integer minor units for display: money(2500, 'EUR') === '25.00 EUR'. */
    function money(int $minor, string $currency): string
    {
        return Money::format($minor, $currency);
    }
}
