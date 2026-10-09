<?php

namespace App\Support;

/**
 * Holds the id of the current request so that logs, audit entries and
 * error pages can all refer to the same value.
 */
final class RequestId
{
    private ?string $id = null;

    public function set(string $id): void
    {
        $this->id = $id;
    }

    public function get(): string
    {
        return $this->id ??= bin2hex(random_bytes(8));
    }
}
