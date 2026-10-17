<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Short error text for places staff see (webhook events, failed jobs). A
 * database error message carries the SQL with its bound values, which may be
 * personal data or secrets; only the class and SQLSTATE are kept. The full
 * error stays in the application log.
 */
final class ErrorSummary
{
    public static function of(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            return QueryException::class.self::sqlState($e->getMessage());
        }

        return mb_substr(get_class($e).': '.$e->getMessage(), 0, 1000);
    }

    /** For the first line of a stored exception (failed_jobs.exception). */
    public static function fromLine(string $line): string
    {
        if (str_contains($line, 'QueryException')) {
            return QueryException::class.self::sqlState($line);
        }

        return $line;
    }

    private static function sqlState(string $message): string
    {
        return preg_match('/SQLSTATE\[(\w+)\]/', $message, $m) ? " (SQLSTATE {$m[1]})" : '';
    }
}
