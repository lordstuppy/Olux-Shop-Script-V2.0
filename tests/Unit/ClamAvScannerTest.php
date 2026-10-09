<?php

namespace Tests\Unit;

use App\Services\Security\ClamAvScanner;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the INSTREAM protocol against a tiny fake clamd running in a
 * forked child process. Plain PHPUnit (no app, no database connection) so
 * the fork shares nothing that its exit could close.
 */
#[RequiresPhpExtension('pcntl')]
class ClamAvScannerTest extends TestCase
{
    private const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    private function withFakeClamd(callable $test): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, $errstr);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

        $pid = pcntl_fork();
        if ($pid === 0) {
            // Child: answer one connection like clamd, then exit.
            $conn = stream_socket_accept($server, 10);
            $command = fread($conn, 10);
            if ($command === "zPING\0") {
                fwrite($conn, "PONG\0");
                fclose($conn);
                posix_kill(posix_getpid(), SIGKILL);
            }
            $data = '';
            while (true) {
                $len = unpack('N', fread($conn, 4))[1];
                if ($len === 0) {
                    break;
                }
                $chunk = '';
                while (strlen($chunk) < $len) {
                    $chunk .= fread($conn, $len - strlen($chunk));
                }
                $data .= $chunk;
            }
            $reply = $command !== "zINSTREAM\0" ? "UNKNOWN COMMAND\0"
                : (str_contains($data, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE') ? "stream: Eicar-Test-Signature FOUND\0" : "stream: OK\0");
            fwrite($conn, $reply);
            fclose($conn);
            // Terminate at once: no shutdown handlers in the forked copy.
            posix_kill(posix_getpid(), SIGKILL);
        }

        try {
            $test(new ClamAvScanner('127.0.0.1', $port, 5));
        } finally {
            fclose($server);
            pcntl_waitpid($pid, $status);
        }
    }

    public function test_clean_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, str_repeat('harmless ', 20000));
        $this->withFakeClamd(fn ($scanner) => $this->assertSame(['status' => 'clean', 'detail' => null], $scanner->scan($path)));
        unlink($path);
    }

    public function test_infected_file_reports_signature(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, self::EICAR);
        $this->withFakeClamd(fn ($scanner) => $this->assertSame(['status' => 'infected', 'detail' => 'Eicar-Test-Signature'], $scanner->scan($path)));
        unlink($path);
    }

    public function test_ping(): void
    {
        $this->withFakeClamd(fn ($scanner) => $this->assertSame(['ok' => true, 'detail' => 'PONG'], $scanner->ping()));
        $this->assertFalse((new ClamAvScanner('', 3310, 1))->ping()['ok']);
        $this->assertFalse((new ClamAvScanner('127.0.0.1', 1, 1))->ping()['ok']);
    }

    public function test_unreachable_or_unconfigured_scanner_is_an_error(): void
    {
        $this->assertSame('error', (new ClamAvScanner('', 3310, 1))->scan(__FILE__)['status']);
        $this->assertSame('error', (new ClamAvScanner('127.0.0.1', 1, 1))->scan(__FILE__)['status']);
    }
}
