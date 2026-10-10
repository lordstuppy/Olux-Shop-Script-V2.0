<?php

namespace App\Http\Controllers;

use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;
use App\Services\AuditLogger;
use App\Services\Shkeeper\ShkeeperClient;
use App\Services\WebhookProcessor;
use App\Support\GatewayLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * POST /webhooks/shkeeper
 *
 * 1. Verify X-Shkeeper-Signature (HMAC-SHA256, constant-time compare) and
 *    the timestamp window. Unsigned or mismatched requests get 401.
 * 2. Store the event; an identical body seen before is acknowledged and
 *    skipped.
 * 3. Process it. Transient failures are retried by the queue with backoff.
 *
 * Shkeeper treats only HTTP 202 as delivered and otherwise re-sends every
 * 60 seconds, so 202 is returned once the event is safely stored.
 */
class WebhookController extends Controller
{
    public function shkeeper(Request $request, ShkeeperClient $client, WebhookProcessor $processor, AuditLogger $audit): JsonResponse
    {
        $allowed = array_filter(array_map('trim', explode(',', (string) config('shop.webhook_allowed_ips'))));
        if ($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            Log::warning('Webhook from ip {ip} outside SHKEEPER_WEBHOOK_ALLOWED_IPS rejected', ['ip' => $request->ip()]);
            GatewayLog::record('webhook', 'rejected_ip', ['ip' => $request->ip(), 'http_status' => 403]);
            $audit->log('webhook.rejected_ip', null, ['channel' => 'payment', 'ip' => $request->ip()]);

            return response()->json(['message' => 'Source address not allowed.'], 403);
        }

        $raw = $request->getContent();
        $signature = $request->header('X-Shkeeper-Signature');
        $timestamp = $request->header('X-Shkeeper-Timestamp');

        if (! $client->verifyWebhookSignature($raw, $signature, $timestamp)) {
            Log::warning('Webhook signature mismatch from ip {ip}', [
                'ip' => $request->ip(),
                'has_signature' => $signature !== null,
                'has_timestamp' => $timestamp !== null,
            ]);
            $reason = $signature === null || $timestamp === null ? 'missing signature or timestamp' : 'signature mismatch or stale timestamp';
            GatewayLog::record('webhook', 'rejected_signature', ['ip' => $request->ip(), 'http_status' => 401, 'message' => $reason]);
            // A forged or replayed callback is a security event (the route is rate limited).
            $audit->log('webhook.rejected_signature', null, ['channel' => 'payment', 'reason' => $reason, 'bytes' => strlen($raw)]);

            return response()->json(['message' => 'Invalid or missing signature.'], 401);
        }

        $payload = json_decode($raw, true);
        // Shkeeper sends a JSON object whose fields we read are plain values;
        // arrays, scalars, empty objects and nested values in those fields are refused.
        if (! is_array($payload) || array_is_list($payload) || ! self::hasScalarFields($payload)) {
            Log::warning('Webhook with signed but unparseable body from ip {ip}', ['ip' => $request->ip()]);
            GatewayLog::record('webhook', 'bad_request', ['ip' => $request->ip(), 'http_status' => 400, 'message' => 'body is not a JSON object']);

            return response()->json(['message' => 'Body is not a JSON object.'], 400);
        }

        try {
            // Own transaction (a savepoint when nested) so a duplicate insert
            // cannot poison an enclosing PostgreSQL transaction.
            $event = DB::transaction(fn () => WebhookEvent::create([
                'provider' => 'shkeeper',
                'event_key' => hash('sha256', $raw),
                'external_id' => mb_substr((string) ($payload['external_id'] ?? ''), 0, 64) ?: null,
                'payload' => $payload,
                'status' => WebhookEventStatus::Received,
                'source_ip' => $request->ip(),
            ]));
        } catch (UniqueConstraintViolationException) {
            Log::info('Duplicate Shkeeper webhook for {external_id} acknowledged without processing', [
                'external_id' => $payload['external_id'] ?? null,
            ]);
            GatewayLog::record('webhook', 'duplicate', ['ip' => $request->ip(), 'http_status' => 202, 'external_id' => (string) ($payload['external_id'] ?? '')]);

            return response()->json(['message' => 'Duplicate event ignored.'], 202);
        }

        $processor->process($event);
        GatewayLog::record('webhook', $event->status === WebhookEventStatus::Failed ? 'error' : 'ok', [
            'ip' => $request->ip(), 'http_status' => 202, 'external_id' => $event->external_id,
            'message' => trim($event->status->value.' '.($payload['status'] ?? '').' '.($event->last_error ?? '')),
        ]);

        return response()->json(['message' => 'Accepted.', 'status' => $event->status->value], 202);
    }

    /** The fields the shop reads must be strings, numbers, booleans or null. */
    private static function hasScalarFields(array $payload): bool
    {
        foreach (['external_id', 'crypto', 'addr', 'fiat', 'balance_fiat', 'balance_crypto', 'paid', 'status', 'fee_percent', 'overpaid_fiat'] as $field) {
            if (isset($payload[$field]) && ! is_scalar($payload[$field])) {
                return false;
            }
        }

        return true;
    }
}
