<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;

/**
 * Turns a channel into JSON lines: one object per line with message,
 * level, context (including request_id) and timestamp. Placeholder
 * interpolation comes from the channel's replace_placeholders option.
 */
class JsonFormatterTap
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true, false, true);
            $formatter->includeStacktraces(true);
            $handler->setFormatter($formatter);
        }
    }
}
