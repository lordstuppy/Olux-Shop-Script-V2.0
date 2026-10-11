<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class ShkeeperException extends RuntimeException
{
    /** Set when Shkeeper itself answered 404 with its JSON error body ("not found"). */
    public bool $shkeeperNotFound = false;

    /**
     * @param  bool  $outcomeUnknown  the request may have reached Shkeeper (timeout, dropped
     *                                connection, 5xx): a transfer might be under way, so it must
     *                                not be treated as failed or retried until a status poll says so
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, public readonly bool $outcomeUnknown = false)
    {
        parent::__construct($message, $code, $previous);
    }
}
