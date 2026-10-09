<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business rule failure whose message is safe and useful to show to the
 * person who triggered it. Never put internal details in the message.
 */
class UserFacingException extends RuntimeException
{
}
