<?php

namespace App\Services\Security;

/**
 * Minimal clamd client using the INSTREAM command over TCP
 * (https://docs.clamav.net/manual/Usage/Scanning.html#clamd).
 */
class ClamAvScanner implements VirusScanner
{
    private const CHUNK = 65536;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $timeout,
    ) {}

    public function scan(string $absolutePath): array
    {
        if ($this->host === '') {
            return ['status' => 'error', 'detail' => 'CLAMAV_HOST is not configured'];
        }

        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout);
        if ($socket === false) {
            return ['status' => 'error', 'detail' => "clamd unreachable: {$errstr}"];
        }
        stream_set_timeout($socket, $this->timeout);

        $file = fopen($absolutePath, 'rb');
        if ($file === false) {
            fclose($socket);

            return ['status' => 'error', 'detail' => 'file not readable'];
        }

        try {
            fwrite($socket, "zINSTREAM\0");
            while (! feof($file)) {
                $chunk = (string) fread($file, self::CHUNK);
                if ($chunk === '') {
                    break;
                }
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
            fwrite($socket, pack('N', 0));
            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        } finally {
            fclose($file);
            fclose($socket);
        }

        // "stream: OK", "stream: Eicar-Test-Signature FOUND", "INSTREAM size limit exceeded. ERROR"
        if (str_ends_with($reply, ': OK')) {
            return ['status' => 'clean', 'detail' => null];
        }
        if (str_ends_with($reply, ' FOUND')) {
            $signature = trim(substr($reply, strpos($reply, ':') + 1, -strlen(' FOUND')));

            return ['status' => 'infected', 'detail' => mb_substr($signature, 0, 200)];
        }

        return ['status' => 'error', 'detail' => mb_substr($reply === '' ? 'empty reply from clamd' : $reply, 0, 200)];
    }
}
