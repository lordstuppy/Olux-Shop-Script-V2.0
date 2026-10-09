<?php

/*
 * Minimal Shkeeper API emulator for local development and integration tests.
 * Run with: php -S 0.0.0.0:8081 docker/shkeeper-mock/server.php
 *
 * Implements:
 *   GET  /api/v1/crypto
 *   POST /api/v1/{crypto}/payment_request      (X-Shkeeper-Api-Key required)
 *   GET  /api/v1/invoices/{external_id}         (X-Shkeeper-Api-Key required)
 * Test controls (not part of Shkeeper):
 *   POST /__mock/pay/{external_id}   body: {"amount": "25.00", "status": "PAID"}
 *        Records a payment and sends a signed callback to the invoice callback_url.
 *   POST /__mock/reset
 *
 * Environment: MOCK_API_KEY (default dev-api-key), MOCK_STATE_FILE.
 * Never expose this server outside a test network.
 */

$apiKey = getenv('MOCK_API_KEY') ?: 'dev-api-key';
$stateFile = getenv('MOCK_STATE_FILE') ?: sys_get_temp_dir().'/shkeeper-mock-state.json';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');

function respond(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
}

function load(string $file): array
{
    return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
}

function save(string $file, array $state): void
{
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
}

function authorised(string $apiKey): bool
{
    return hash_equals($apiKey, (string) ($_SERVER['HTTP_X_SHKEEPER_API_KEY'] ?? ''));
}

$cryptos = [
    ['name' => 'BTC', 'display_name' => 'Bitcoin'],
    ['name' => 'LTC', 'display_name' => 'Litecoin'],
    ['name' => 'ETH', 'display_name' => 'Ethereum'],
    ['name' => 'ETH-USDT', 'display_name' => 'Tether ERC20'],
];
$rates = ['BTC' => '60000', 'LTC' => '80', 'ETH' => '3000', 'ETH-USDT' => '1'];

if ($method === 'GET' && $path === '/api/v1/crypto') {
    respond(200, ['status' => 'success', 'crypto' => array_column($cryptos, 'name'), 'crypto_list' => $cryptos]);

    return;
}

if ($method === 'POST' && preg_match('#^/api/v1/([A-Za-z0-9_-]+)/payment_request$#', $path, $m)) {
    if (! authorised($apiKey)) {
        respond(401, ['status' => 'error', 'message' => 'Invalid API key']);

        return;
    }
    $crypto = $m[1];
    if (! isset($rates[$crypto])) {
        respond(200, ['status' => 'error', 'message' => "{$crypto} payment gateway is unavailable"]);

        return;
    }
    $req = json_decode($body, true) ?: [];
    foreach (['external_id', 'fiat', 'amount', 'callback_url'] as $field) {
        if (empty($req[$field])) {
            respond(200, ['status' => 'error', 'message' => "missing {$field}"]);

            return;
        }
    }
    $state = load($stateFile);
    $id = $state[$req['external_id']]['id'] ?? (count($state) + 1);
    $cryptoAmount = number_format((float) $req['amount'] / (float) $rates[$crypto], 8, '.', '');
    $state[$req['external_id']] = [
        'id' => $id,
        'external_id' => $req['external_id'],
        'crypto' => $crypto,
        'fiat' => $req['fiat'],
        'amount_fiat' => $req['amount'],
        'balance_fiat' => $state[$req['external_id']]['balance_fiat'] ?? '0',
        'status' => $state[$req['external_id']]['status'] ?? 'UNPAID',
        'callback_url' => $req['callback_url'],
        'wallet' => 'mock'.strtolower($crypto).substr(hash('sha256', $req['external_id'].$crypto), 0, 30),
        'txs' => $state[$req['external_id']]['txs'] ?? [],
    ];
    save($stateFile, $state);
    respond(200, [
        'status' => 'success',
        'id' => $id,
        'amount' => $cryptoAmount,
        'display_name' => $crypto,
        'exchange_rate' => $rates[$crypto],
        'recalculate_after' => 0,
        'wallet' => $state[$req['external_id']]['wallet'],
    ]);

    return;
}

if ($method === 'GET' && preg_match('#^/api/v1/invoices/([A-Za-z0-9-]+)$#', $path, $m)) {
    if (! authorised($apiKey)) {
        respond(401, ['status' => 'error', 'message' => 'Invalid API key']);

        return;
    }
    $invoice = load($stateFile)[$m[1]] ?? null;
    respond(200, ['status' => 'success', 'invoices' => $invoice ? [$invoice] : []]);

    return;
}

if ($method === 'POST' && preg_match('#^/__mock/pay/([A-Za-z0-9-]+)$#', $path, $m)) {
    $state = load($stateFile);
    $invoice = $state[$m[1]] ?? null;
    if ($invoice === null) {
        respond(404, ['status' => 'error', 'message' => 'unknown invoice']);

        return;
    }
    $req = json_decode($body, true) ?: [];
    $amount = (string) ($req['amount'] ?? $invoice['amount_fiat']);
    $invoice['balance_fiat'] = $amount;
    $invoice['status'] = $req['status'] ?? (bccomp_like($amount, $invoice['amount_fiat']) >= 0 ? 'PAID' : 'PARTIAL');
    $invoice['txs'][] = ['txid' => bin2hex(random_bytes(16)), 'amount_fiat' => $amount, 'trigger' => true];
    $state[$m[1]] = $invoice;
    save($stateFile, $state);

    $payload = json_encode([
        'external_id' => $invoice['external_id'],
        'crypto' => $invoice['crypto'],
        'addr' => $invoice['wallet'],
        'fiat' => $req['fiat'] ?? $invoice['fiat'],
        'balance_fiat' => $amount,
        'balance_crypto' => '0',
        'paid' => in_array($invoice['status'], ['PAID', 'OVERPAID'], true),
        'status' => $invoice['status'],
        'transactions' => $invoice['txs'],
        'fee_percent' => '0',
        'overpaid_fiat' => '0.00',
    ], JSON_UNESCAPED_SLASHES);
    $ts = (string) time();
    $ch = curl_init($invoice['callback_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Shkeeper-Api-Key: '.$apiKey,
            'X-Shkeeper-Timestamp: '.$ts,
            'X-Shkeeper-Signature: '.hash_hmac('sha256', $ts.'.'.$payload, $apiKey),
        ],
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    respond(200, ['status' => 'success', 'callback_http_status' => $code, 'callback_response' => json_decode((string) $response, true)]);

    return;
}

if ($method === 'POST' && $path === '/__mock/reset') {
    save($stateFile, []);
    respond(200, ['status' => 'success']);

    return;
}

respond(404, ['status' => 'error', 'message' => 'not found']);

/** Compares two non-negative decimal strings without floats. */
function bccomp_like(string $a, string $b): int
{
    [$ai, $af] = array_pad(explode('.', $a, 2), 2, '');
    [$bi, $bf] = array_pad(explode('.', $b, 2), 2, '');
    $len = max(strlen($af), strlen($bf));
    $a = ltrim($ai.str_pad($af, $len, '0'), '0');
    $b = ltrim($bi.str_pad($bf, $len, '0'), '0');

    return strlen($a) === strlen($b) ? strcmp($a, $b) <=> 0 : strlen($a) <=> strlen($b);
}
