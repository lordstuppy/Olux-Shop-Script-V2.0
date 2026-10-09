<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes one row to gateway_logs. Channels: webhook (payment callbacks),
 * payout_webhook (payout callbacks), api (calls to Shkeeper). Outcomes:
 * ok, duplicate, rejected_signature, rejected_ip, bad_request, error,
 * timeout. Logging must never break a payment, so failures are swallowed.
 */
final class GatewayLog
{
    public const CHANNELS = ['webhook', 'payout_webhook', 'api'];

    public const OUTCOMES = ['ok', 'duplicate', 'rejected_signature', 'rejected_ip', 'bad_request', 'error', 'timeout'];

    /** @param array{action?: ?string, http_status?: ?int, duration_ms?: ?int, external_id?: ?string, ip?: ?string, message?: ?string} $data */
    public static function record(string $channel, string $outcome, array $data = []): void
    {
        try {
            // Savepoint, so a failed insert cannot abort an enclosing PostgreSQL transaction.
            DB::transaction(fn () => DB::table('gateway_logs')->insert([
                'gateway' => 'shkeeper',
                'channel' => $channel,
                'outcome' => $outcome,
                'action' => isset($data['action']) ? mb_substr($data['action'], 0, 128) : null,
                'http_status' => $data['http_status'] ?? null,
                'duration_ms' => $data['duration_ms'] ?? null,
                'external_id' => isset($data['external_id']) ? mb_substr($data['external_id'], 0, 64) : null,
                'ip' => $data['ip'] ?? null,
                'message' => isset($data['message']) ? mb_substr($data['message'], 0, 500) : null,
                'request_id' => app(RequestId::class)->get(),
                'created_at' => now(),
            ]));
        } catch (Throwable $e) {
            Log::warning('Could not write gateway log: {reason}', ['reason' => $e->getMessage()]);
        }
    }
}
