<?php

namespace App\Http\Controllers;

use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;
use App\Services\AuditLogger;
use App\Services\Shkeeper\ShkeeperClient;
use App\Services\ShkeeperPayoutService;
use App\Support\GatewayLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function __invoke(Request $request, ShkeeperClient $client, ShkeeperPayoutService $payouts, AuditLogger $audit): JsonResponse
    {
        $allowed = array_filter(array_map('trim', explode(',', (string) config('shop.webhook_allowed_ips'))));
        if ($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            Log::warning('Payout webhook from ip {ip} outside SHKEEPER_WEBHOOK_ALLOWED_IPS rejected', ['ip' => $request->ip()]);
            GatewayLog::record('payout_webhook', 'rejected_ip', ['ip' => $request->ip(), 'http_status' => 403]);
            $audit->log('webhook.rejected_ip', null, ['channel' => 'payout', 'ip' => $request->ip()]);

            return response()->json(['message' => 'Source address not allowed.'], 403);
        }

        $raw = $request->getContent();
        if (! $client->verifyWebhookSignature($raw, $request->header('X-Shkeeper-Signature'), $request->header('X-Shkeeper-Timestamp'))) {
            Log::warning('Payout webhook signature mismatch from ip {ip}', ['ip' => $request->ip()]);
            GatewayLog::record('payout_webhook', 'rejected_signature', ['ip' => $request->ip(), 'http_status' => 401]);
            $audit->log('webhook.rejected_signature', null, ['channel' => 'payout', 'bytes' => strlen($raw)]);

            return response()->json(['message' => 'Invalid or missing signature.'], 401);
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload) || array_is_list($payload) || ! is_string($payload['external_id'] ?? null)) {
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

        $outcome = $payouts->handleResult($payload['external_id'], (string) ($payload['status'] ?? ''), isset($payload['tx_hash']) ? (string) $payload['tx_hash'] : null);
        $event->forceFill([
            'status' => $outcome === 'processed' ? WebhookEventStatus::Processed : WebhookEventStatus::Ignored,
            'attempts' => 1,
            'processed_at' => now(),
        ])->save();

        GatewayLog::record('payout_webhook', 'ok', ['ip' => $request->ip(), 'http_status' => 202, 'external_id' => $payload['external_id'],
            'message' => trim($outcome.' '.($payload['status'] ?? ''))]);

        return response()->json(['message' => 'Accepted.', 'status' => $outcome], 202);
    }
}
