<?php

namespace App\Http\Controllers;

use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;
use App\Services\Shkeeper\ShkeeperClient;
use App\Services\WebhookProcessor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
    public function shkeeper(Request $request, ShkeeperClient $client, WebhookProcessor $processor): JsonResponse
    {
        $raw = $request->getContent();
        $signature = $request->header('X-Shkeeper-Signature');
        $timestamp = $request->header('X-Shkeeper-Timestamp');

        if (! $client->verifyWebhookSignature($raw, $signature, $timestamp)) {
            Log::warning('Webhook signature mismatch from ip {ip}', [
                'ip' => $request->ip(),
                'has_signature' => $signature !== null,
                'has_timestamp' => $timestamp !== null,
            ]);

            return response()->json(['message' => 'Invalid or missing signature.'], 401);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            Log::warning('Webhook with signed but unparseable body from ip {ip}', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Body is not a JSON object.'], 400);
        }

        try {
            $event = WebhookEvent::create([
                'provider' => 'shkeeper',
                'event_key' => hash('sha256', $raw),
                'external_id' => mb_substr((string) ($payload['external_id'] ?? ''), 0, 64) ?: null,
                'payload' => $payload,
                'status' => WebhookEventStatus::Received,
                'source_ip' => $request->ip(),
            ]);
        } catch (UniqueConstraintViolationException) {
            Log::info('Duplicate Shkeeper webhook for {external_id} acknowledged without processing', [
                'external_id' => $payload['external_id'] ?? null,
            ]);

            return response()->json(['message' => 'Duplicate event ignored.'], 202);
        }

        $processor->process($event);

        return response()->json(['message' => 'Accepted.', 'status' => $event->status->value], 202);
    }
}
