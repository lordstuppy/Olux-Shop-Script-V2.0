<?php

namespace App\Services\Shkeeper;

/**
 * One entry of GET /api/v1/invoices/{external_id}.
 */
final readonly class ShkeeperInvoiceStatus
{
    public function __construct(
        public string $externalId,
        public string $status,
        public string $fiat,
        public string $amountFiat,
        public string $balanceFiat,
        public array $raw,
    ) {}

    public function isPaid(): bool
    {
        return in_array($this->status, ['PAID', 'OVERPAID'], true);
    }

    /**
     * Shapes the status like a callback payload so both paths share one
     * processing routine.
     */
    public function toNotificationPayload(): array
    {
        return [
            'external_id' => $this->externalId,
            'fiat' => $this->fiat,
            'balance_fiat' => $this->balanceFiat,
            'paid' => $this->isPaid(),
            'status' => $this->status,
            'source' => 'status_poll',
        ];
    }
}
