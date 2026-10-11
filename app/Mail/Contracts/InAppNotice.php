<?php

namespace App\Mail\Contracts;

/**
 * A mail whose event also belongs in the recipient's notification centre.
 * The entry uses the mail's subject as its title.
 */
interface InAppNotice
{
    /** order, sale, dispute, payout, product, review, ticket, account */
    public function inAppKind(): string;

    /** Where the entry links to; a path inside the shop. */
    public function inAppUrl(): ?string;
}
