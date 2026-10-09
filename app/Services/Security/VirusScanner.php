<?php

namespace App\Services\Security;

interface VirusScanner
{
    /**
     * @return array{status: 'clean'|'infected'|'error', detail: ?string}
     */
    public function scan(string $absolutePath): array;

    /**
     * Whether the scanner answers, for the system health page.
     *
     * @return array{ok: bool, detail: string}
     */
    public function ping(): array;
}
