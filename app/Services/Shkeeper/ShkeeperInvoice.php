<?php

namespace App\Services\Shkeeper;

/**
 * Result of POST /api/v1/{crypto}/payment_request.
 */
final readonly class ShkeeperInvoice
{
    public function __construct(
        public string $id,
        public string $crypto,
        public string $displayName,
        public string $wallet,
        public string $cryptoAmount,
        public string $exchangeRate,
        public ?string $recalculateAfter,
    ) {}
}
