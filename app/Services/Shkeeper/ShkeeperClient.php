<?php

namespace App\Services\Shkeeper;

use App\Exceptions\ShkeeperException;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for a self-hosted Shkeeper instance.
 *
 * API reference: https://github.com/vsys-host/shkeeper.io (README, "API").
 * Shkeeper has no hosted checkout page: an invoice returns a wallet address
 * and a crypto amount that the shop renders itself.
 */
class ShkeeperClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly ?string $webhookSecret,
        private readonly int $webhookTolerance = 300,
        private readonly int $timeout = 10,
    ) {}

    /**
     * @return list<array{name: string, display_name: string}>
     */
    public function availableCryptos(): array
    {
        $response = $this->send('GET', '/api/v1/crypto', null, false);
        $list = $response->json('crypto_list');
        if (! is_array($list)) {
            throw new ShkeeperException('Shkeeper returned no crypto_list.');
        }

        $out = [];
        foreach ($list as $entry) {
            if (is_array($entry) && isset($entry['name']) && self::isValidCryptoName((string) $entry['name'])) {
                $out[] = ['name' => (string) $entry['name'], 'display_name' => (string) ($entry['display_name'] ?? $entry['name'])];
            }
        }

        return $out;
    }

    /**
     * Creates (or, for the same external id, updates) an invoice.
     */
    public function createInvoice(string $crypto, string $externalId, int $amountMinor, string $currency, string $callbackUrl): ShkeeperInvoice
    {
        if (! self::isValidCryptoName($crypto)) {
            throw new ShkeeperException("Invalid crypto name \"{$crypto}\".");
        }

        $payload = [
            'external_id' => $externalId,
            'fiat' => $currency,
            'amount' => Money::toDecimal($amountMinor, $currency),
            'callback_url' => $callbackUrl,
        ];

        Log::debug('ShkeeperClient: createInvoice request payload hashed for order {public_id}', [
            'public_id' => $externalId,
            'payload_sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'crypto' => $crypto,
        ]);

        $response = $this->send('POST', '/api/v1/'.rawurlencode($crypto).'/payment_request', $payload);
        $data = $response->json();
        foreach (['id', 'wallet', 'amount'] as $field) {
            if (! isset($data[$field]) || $data[$field] === '') {
                throw new ShkeeperException("Shkeeper payment_request response is missing \"{$field}\".");
            }
        }

        return new ShkeeperInvoice(
            id: (string) $data['id'],
            crypto: $crypto,
            displayName: (string) ($data['display_name'] ?? $crypto),
            wallet: (string) $data['wallet'],
            cryptoAmount: (string) $data['amount'],
            exchangeRate: (string) ($data['exchange_rate'] ?? ''),
            recalculateAfter: isset($data['recalculate_after']) ? (string) $data['recalculate_after'] : null,
        );
    }

    public function getInvoiceStatus(string $externalId): ?ShkeeperInvoiceStatus
    {
        $response = $this->send('GET', '/api/v1/invoices/'.rawurlencode($externalId));
        $invoices = $response->json('invoices');
        if (! is_array($invoices) || $invoices === []) {
            return null;
        }
        $invoice = $invoices[0];

        return new ShkeeperInvoiceStatus(
            externalId: (string) ($invoice['external_id'] ?? $externalId),
            status: (string) ($invoice['status'] ?? 'UNPAID'),
            fiat: (string) ($invoice['fiat'] ?? ''),
            amountFiat: (string) ($invoice['amount_fiat'] ?? '0'),
            balanceFiat: (string) ($invoice['balance_fiat'] ?? '0'),
            raw: $invoice,
        );
    }

    /**
     * Verifies X-Shkeeper-Signature: lowercase hex HMAC-SHA256 over
     * "{timestamp}.{raw body}", keyed with the webhook secret, compared in
     * constant time. Requests outside the timestamp tolerance are rejected
     * to limit replay.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature, ?string $timestamp, ?int $now = null): bool
    {
        if ($this->webhookSecret === null || $this->webhookSecret === '') {
            Log::error('ShkeeperClient: SHKEEPER_WEBHOOK_SECRET is not configured; rejecting webhook');

            return false;
        }
        if ($signature === null || $timestamp === null || ! ctype_digit($timestamp)) {
            return false;
        }
        $now ??= time();
        if (abs($now - (int) $timestamp) > $this->webhookTolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->webhookSecret);

        return hash_equals($expected, strtolower(trim($signature)));
    }

    /** Builds the headers Shkeeper would send; used by tests and the mock. */
    public static function signatureHeaders(string $rawBody, string $secret, int $timestamp): array
    {
        return [
            'X-Shkeeper-Timestamp' => (string) $timestamp,
            'X-Shkeeper-Signature' => hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret),
        ];
    }

    public static function isValidCryptoName(string $crypto): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{1,32}$/', $crypto);
    }

    private function send(string $method, string $path, ?array $json = null, bool $authenticated = true): Response
    {
        if ($this->baseUrl === '') {
            throw new ShkeeperException('SHKEEPER_BASE_URL is not configured.');
        }
        if ($authenticated && ($this->apiKey === null || $this->apiKey === '')) {
            throw new ShkeeperException('SHKEEPER_API_KEY is not configured.');
        }

        try {
            $response = $this->request($authenticated)->send($method, $this->baseUrl.$path, $json === null ? [] : ['json' => $json]);
        } catch (ConnectionException $e) {
            throw new ShkeeperException("Shkeeper is unreachable: {$e->getMessage()}", 0, $e);
        }

        if (! $response->successful()) {
            throw new ShkeeperException("Shkeeper {$method} {$path} failed with HTTP {$response->status()}.");
        }
        if ($response->json('status') === 'error') {
            throw new ShkeeperException('Shkeeper error: '.mb_substr((string) $response->json('message'), 0, 200));
        }

        return $response;
    }

    private function request(bool $authenticated): PendingRequest
    {
        $request = $this->http->acceptJson()->timeout($this->timeout)->connectTimeout(5);

        return $authenticated ? $request->withHeaders(['X-Shkeeper-Api-Key' => (string) $this->apiKey]) : $request;
    }
}
