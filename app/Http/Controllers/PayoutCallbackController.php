<?php

namespace App\Http\Controllers;

use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;
use App\Services\AuditLogger;
use App\Services\Shkeeper\ShkeeperClient;
use App\Services\StaffAlerts;
use App\Services\WebhookProcessor;
use App\Support\GatewayLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * POST /webhooks/shkeeper/payouts: Shkeeper's payout callback, signed the
 * same way as payment callbacks. Body: payout_id, external_id, tx_hash,
 * status, amount, crypto, amount_fiat, currency_fiat, timestamp.
 */
class PayoutCallbackController extends Controller
{
    private const MAX_BODY_BYTES = 65536;

    public function __invoke(Request $request, ShkeeperClient $client, WebhookProcessor $processor, AuditLogger $audit): JsonResponse
    {
        $allowed = array_filter(array_map('trim', explode(',', (string) config('shop.webhook_allowed_ips'))));
        if ($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            Log::warning('Payout webhook from ip {ip} outside SHKEEPER_WEBHOOK_ALLOWED_IPS rejected', ['ip' => $request->ip()]);
            GatewayLog::record('payout_webhook', 'rejected_ip', ['ip' => $request->ip(), 'http_status' => 403]);
            self::auditRejection($audit, 'rejected_ip', ['channel' => 'payout', 'ip' => $request->ip()], $request->ip());

            return response()->json(['message' => 'Source address not allowed.'], 403);
        }

        // Gateway callbacks are a few hundred bytes; refuse large bodies before hashing them.
        if ((int) $request->header('Content-Length', '0') > self::MAX_BODY_BYTES || strlen($raw = $request->getContent()) > self::MAX_BODY_BYTES) {
            GatewayLog::record('payout_webhook', 'bad_request', ['ip' => $request->ip(), 'http_status' => 413, 'message' => 'body too large']);

            return response()->json(['message' => 'Body too large.'], 413);
        }
        if (! $client->verifyWebhookSignature($raw, $request->header('X-Shkeeper-Signature'), $request->header('X-Shkeeper-Timestamp'))) {
            Log::warning('Payout webhook signature mismatch from ip {ip}', ['ip' => $request->ip()]);
            GatewayLog::record('payout_webhook', 'rejected_signature', ['ip' => $request->ip(), 'http_status' => 401]);
            self::auditRejection($audit, 'rejected_signature', ['channel' => 'payout', 'bytes' => strlen($raw)], $request->ip());

            return response()->json(['message' => 'Invalid or missing signature.'], 401);
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload) || array_is_list($payload) || ! is_string($payload['external_id'] ?? null)
            || array_filter(array_intersect_key($payload, array_flip(['status', 'tx_hash', 'amount', 'crypto'])), fn ($v) => ! is_scalar($v) && $v !== null) !== []) {
            GatewayLog::record('payout_webhook', 'bad_request', ['ip' => $request->ip(), 'http_status' => 400]);

            return response()->json(['message' => 'Body is not a payout callback.'], 400);
        }

        try {
            $event = DB::transaction(fn () => WebhookEvent::create([
                'provider' => 'shkeeper-payout',
                'event_key' => hash('sha256', $raw),
                'external_id' => mb_substr($payload['external_id'], 0, 64),
                'payload' => $payload,
                'status' => WebhookEventStatus::Received,
                'source_ip' => $request->ip(),
            ]));
        } catch (UniqueConstraintViolationException) {
            GatewayLog::record('payout_webhook', 'duplicate', ['ip' => $request->ip(), 'http_status' => 202, 'external_id' => $payload['external_id']]);

            return response()->json(['message' => 'Duplicate event ignored.'], 202);
        }

        // Same retry machine as payment callbacks: a failure leaves a "failed"
        // event that is retried with backoff (Shkeeper's redelivery is a duplicate).
        $processor->process($event);
        GatewayLog::record('payout_webhook', $event->status === WebhookEventStatus::Failed ? 'error' : 'ok', ['ip' => $request->ip(), 'http_status' => 202,
            'external_id' => $payload['external_id'], 'message' => trim($event->status->value.' '.($payload['status'] ?? '').' '.($event->last_error ?? ''))]);

        return response()->json(['message' => 'Accepted.', 'status' => $event->status->value], 202);
    }

    /**
     * Rejected callbacks are audited, but at most once per address and reason
     * per minute, so a flood of forged requests cannot flood the audit log
     * (every request is still counted in the gateway log).
     */
    private static function auditRejection(AuditLogger $audit, string $reason, array $metadata, ?string $ip): void
    {
        if (Cache::add('audit-webhook:'.$reason.':'.$ip, true, 60)) {
            $audit->log('webhook.'.$reason, null, $metadata);
            // Staff hear about it at most once an hour, however many arrive.
            app(StaffAlerts::class)->send(__('Shkeeper callbacks are being rejected'), [
                __('Reason: :reason', ['reason' => $reason === 'rejected_signature' ? __('wrong signature') : __('sender address not allowed')]),
                __('Last sender: :ip', ['ip' => (string) $ip]),
                __('Either the API key or allowed addresses do not match Shkeeper, or someone is sending forged payment callbacks. Paid orders stay unpaid until this is fixed.'),
            ], route('admin.gateway.index'), 'webhook-rejected', 60);
        }
    }
}
