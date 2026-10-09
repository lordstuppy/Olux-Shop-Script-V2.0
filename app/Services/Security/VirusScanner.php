<?php

namespace App\Services\Security;

interface VirusScanner
{
    /**
     * @return array{status: 'clean'|'infected'|'error', detail: ?string}
     */
    public function scan(string $absolutePath): array;
}
