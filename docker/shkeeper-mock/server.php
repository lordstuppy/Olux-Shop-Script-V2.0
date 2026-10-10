<?php

/*
 * Minimal Shkeeper API emulator for local development and integration tests.
 * Run with: php -S 0.0.0.0:8081 docker/shkeeper-mock/server.php
 * (Set PHP_CLI_SERVER_WORKERS=4 or more when using the "timeout" failure mode,
 * otherwise the single-worker built-in server blocks while it sleeps.)
 *
 * Implements:
 *   GET  /api/v1/crypto
 *   POST /api/v1/{crypto}/payment_request      (X-Shkeeper-Api-Key required)
 *   GET  /api/v1/invoices/{external_id}         (X-Shkeeper-Api-Key required)
 *   POST /api/v1/{crypto}/quote                 (X-Shkeeper-Api-Key required)
 *   POST /api/v1/{crypto}/payout                (HTTP Basic auth, MOCK_PAYOUT_USER / MOCK_PAYOUT_PASSWORD)
 *   GET  /api/v1/{crypto}/payout/status?external_id=...  (X-Shkeeper-Api-Key required)
 * Test controls (not part of Shkeeper):
 *   POST /__mock/pay/{external_id}
 *        body: {"amount": "25.00", "status": "PAID", "fiat": "USD",
 *               "repeat": 1, "deliver": true, "delay_callback_ms": 0}   (all optional)
 *        Records a payment and sends a signed callback to the invoice callback_url.
 *        "repeat" sends the identical payload n times in a row (fresh timestamp and
 *        signature each time); "deliver": false records the payment without sending
 *        any callback (lost callback, the shop must poll); "delay_callback_ms" sleeps
 *        before sending. Response: callback_http_status (last), callback_http_statuses
 *        (all), callback_response (last, decoded JSON).
 *   POST /__mock/payout-result/{external_id}   body: {"status": "SUCCESS"|"FAIL"}
 *        Completes a payout and sends a signed payout callback.
 *   POST /__mock/callback-unknown
 *        body: {"external_id": "...", "amount": "10.00", "fiat": "USD", "callback_url": "http://...",
 *               "crypto": "BTC", "status": "PAID"}   (crypto and status optional)
 *        Sends a correctly signed invoice callback for an invoice the mock never created
 *        (nothing is stored). Response: callback_http_status, callback_response.
 *   POST /__mock/send-raw   body: {"callback_url": "http://...", "body": "<raw string>", "sign": true}
 *        Posts an arbitrary raw body (e.g. malformed JSON). With "sign": true (default) the
 *        usual X-Shkeeper-Api-Key / X-Shkeeper-Timestamp / X-Shkeeper-Signature headers are
 *        added. Response: http_status, response_body (raw string).
 *   GET  /__mock/config     Returns the current failure-mode config.
 *   POST /__mock/config     Replaces the failure-mode config (send {} to clear). Body:
 *        {"delay_ms": 0,
 *         "fail_next": [{"match": "payment_request", "mode": "timeout"|"http500"|"error_json"|"bad_json",
 *                        "count": 1, "seconds": 30}]}
 *        Applied to every /api/v1/* request before routing: sleep delay_ms, then the first
 *        fail_next entry whose "match" is a substring of the request path fires and its
 *        count is decremented (entry removed at 0). Modes:
 *          timeout    - sleep "seconds" (default 30), then HTTP 504 with an empty body
 *          http500    - HTTP 500 with an HTML body
 *          error_json - HTTP 200 {"status":"error","message":"mock failure"}
 *          bad_json   - HTTP 200, Content-Type application/json, truncated non-JSON body
 *        Stored in the state file under the reserved key "__config".
 *   POST /__mock/reset      Clears all invoices, payouts and the failure-mode config.
 *
 * Callback signing: X-Shkeeper-Timestamp = unix time, X-Shkeeper-Signature =
 * hex(hmac_sha256(timestamp.'.'.body, api key)).
 *
 * Environment: MOCK_API_KEY (default dev-api-key), MOCK_STATE_FILE.
 * Never expose this server outside a test network.
 */

$apiKey = getenv('MOCK_API_KEY') ?: 'dev-api-key';
$payoutUser = getenv('MOCK_PAYOUT_USER') ?: 'payout-user';
$payoutPassword = getenv('MOCK_PAYOUT_PASSWORD') ?: 'payout-password';
$stateFile = getenv('MOCK_STATE_FILE') ?: sys_get_temp_dir().'/shkeeper-mock-state.json';

const CONFIG_KEY = '__config';
const FAIL_MODES = ['timeout', 'http500', 'error_json', 'bad_json'];

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
    if (! is_file($file)) {
        return [];
    }
    $fh = fopen($file, 'r');
    flock($fh, LOCK_SH); // never read a half-written file
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return json_decode((string) $raw, true) ?: [];
}

function save(string $file, array $state): void
{
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Serialises read-modify-write of the state file across the server's worker
 * processes (without it, concurrent invoice creations got the same id).
 *
 * @return resource
 */
function lockState(string $file)
{
    $handle = fopen($file.'.lock', 'c');
    flock($handle, LOCK_EX);

    return $handle;
}

/** @param  resource  $handle */
function unlockState($handle): void
{
    flock($handle, LOCK_UN);
    fclose($handle);
}

function authorised(string $apiKey): bool
{
    return hash_equals($apiKey, (string) ($_SERVER['HTTP_X_SHKEEPER_API_KEY'] ?? ''));
}

/** Number of invoices in the state (excludes the reserved "payouts" and "__config" keys). */
function invoiceCount(array $state): int
{
    unset($state['payouts'], $state[CONFIG_KEY]);

    return count($state);
}

/** Current failure-mode config with defaults filled in. */
function mockConfig(array $state): array
{
    $config = $state[CONFIG_KEY] ?? [];

    return ['delay_ms' => (int) ($config['delay_ms'] ?? 0), 'fail_next' => array_values($config['fail_next'] ?? [])];
}

/**
 * Atomically finds the first fail_next rule matching $path, decrements its count
 * (removing it at 0) and returns it, or null when no rule matches.
 */
function consumeFailRule(string $file, string $path): ?array
{
    $lock = lockState($file);
    $fh = fopen($file, 'c+');
    if ($fh === false) {
        unlockState($lock);

        return null;
    }
    flock($fh, LOCK_EX);
    $state = json_decode((string) stream_get_contents($fh), true) ?: [];
    $config = mockConfig($state);
    $hit = null;
    foreach ($config['fail_next'] as $i => $rule) {
        if (str_contains($path, (string) $rule['match'])) {
            $hit = $rule;
            $config['fail_next'][$i]['count'] = (int) $rule['count'] - 1;
            if ($config['fail_next'][$i]['count'] <= 0) {
                array_splice($config['fail_next'], $i, 1);
            }
            break;
        }
    }
    if ($hit !== null) {
        $state[CONFIG_KEY] = $config;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($state, JSON_PRETTY_PRINT));
        fflush($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    unlockState($lock);

    return $hit;
}

/**
 * POSTs $payload to $url. When $sign is true the Shkeeper signature headers are added
 * (and X-Shkeeper-Api-Key when $withApiKeyHeader is true).
 *
 * @return array{0: int, 1: string} HTTP status (0 on transport error) and raw response body
 */
function postCallback(string $url, string $payload, string $apiKey, bool $sign = true, bool $withApiKeyHeader = true): array
{
    $headers = ['Content-Type: application/json'];
    if ($sign) {
        $ts = (string) time();
        if ($withApiKeyHeader) {
            $headers[] = 'X-Shkeeper-Api-Key: '.$apiKey;
        }
        $headers[] = 'X-Shkeeper-Timestamp: '.$ts;
        $headers[] = 'X-Shkeeper-Signature: '.hash_hmac('sha256', $ts.'.'.$payload, $apiKey);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return [$code, is_string($response) ? $response : ''];
}

/** Builds the JSON body of an invoice (payment) callback. */
function invoicePayload(array $invoice, string $fiat, string $amount): string
{
    return json_encode([
        'external_id' => $invoice['external_id'],
        'crypto' => $invoice['crypto'],
        'addr' => $invoice['wallet'],
        'fiat' => $fiat,
        'balance_fiat' => $amount,
        'balance_crypto' => '0',
        'paid' => in_array($invoice['status'], ['PAID', 'OVERPAID'], true),
        'status' => $invoice['status'],
        'transactions' => $invoice['txs'],
        'fee_percent' => '0',
        'overpaid_fiat' => '0.00',
    ], JSON_UNESCAPED_SLASHES);
}

$cryptos = [
    ['name' => 'BTC', 'display_name' => 'Bitcoin'],
    ['name' => 'LTC', 'display_name' => 'Litecoin'],
    ['name' => 'ETH', 'display_name' => 'Ethereum'],
    ['name' => 'ETH-USDT', 'display_name' => 'Tether ERC20'],
];
$rates = ['BTC' => '60000', 'LTC' => '80', 'ETH' => '3000', 'ETH-USDT' => '1'];

// Failure injection for the emulated Shkeeper API (never for /__mock/* controls).
if (str_starts_with($path, '/api/v1/')) {
    $delayMs = mockConfig(load($stateFile))['delay_ms'];
    if ($delayMs > 0) {
        usleep($delayMs * 1000);
    }
    $rule = consumeFailRule($stateFile, $path);
    if ($rule !== null) {
        switch ($rule['mode']) {
            case 'timeout':
                sleep((int) ($rule['seconds'] ?? 30));
                http_response_code(504);

                return; // empty body
            case 'http500':
                http_response_code(500);
                header('Content-Type: text/html');
                echo '<html><body><h1>500 Internal Server Error</h1></body></html>';

                return;
            case 'error_json':
                respond(200, ['status' => 'error', 'message' => 'mock failure']);

                return;
            case 'bad_json':
                http_response_code(200);
                header('Content-Type: application/json');
                echo '{"status": "success", "id": ';

                return;
        }
    }
}

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
    if (in_array($req['external_id'], ['payouts', CONFIG_KEY], true)) {
        respond(200, ['status' => 'error', 'message' => 'reserved external_id']);

        return;
    }
    $stateLock = lockState($stateFile);
    $state = load($stateFile);
    $id = $state[$req['external_id']]['id'] ?? (invoiceCount($state) + 1);
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
    unlockState($stateLock);
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
    $stateLock = lockState($stateFile);
    $state = load($stateFile);
    $invoice = $state[$m[1]] ?? null;
    if ($invoice === null) {
        respond(404, ['status' => 'error', 'message' => 'unknown invoice']);

        return;
    }
    $req = json_decode($body, true) ?: [];
    $amount = (string) ($req['amount'] ?? $invoice['amount_fiat']);
    $repeat = max(1, min(100, (int) ($req['repeat'] ?? 1)));
    $deliver = ($req['deliver'] ?? true) !== false;
    $delayCallbackMs = max(0, (int) ($req['delay_callback_ms'] ?? 0));

    $invoice['balance_fiat'] = $amount;
    $invoice['status'] = $req['status'] ?? (bccomp_like($amount, $invoice['amount_fiat']) >= 0 ? 'PAID' : 'PARTIAL');
    $invoice['txs'][] = ['txid' => bin2hex(random_bytes(16)), 'amount_fiat' => $amount, 'trigger' => true];
    $state[$m[1]] = $invoice;
    save($stateFile, $state);
    unlockState($stateLock);

    if (! $deliver) {
        respond(200, ['status' => 'success', 'delivered' => false, 'callback_http_status' => null,
            'callback_http_statuses' => [], 'callback_response' => null]);

        return;
    }
    if ($delayCallbackMs > 0) {
        usleep($delayCallbackMs * 1000);
    }
    $payload = invoicePayload($invoice, (string) ($req['fiat'] ?? $invoice['fiat']), $amount);
    $codes = [];
    $response = '';
    for ($i = 0; $i < $repeat; $i++) {
        [$code, $response] = postCallback($invoice['callback_url'], $payload, $apiKey);
        $codes[] = $code;
    }
    respond(200, ['status' => 'success', 'delivered' => true, 'callback_http_status' => end($codes),
        'callback_http_statuses' => $codes, 'callback_response' => json_decode($response, true)]);

    return;
}

if ($method === 'POST' && preg_match('#^/api/v1/([A-Za-z0-9_-]+)/quote$#', $path, $m)) {
    if (! authorised($apiKey)) {
        respond(401, ['status' => 'error', 'message' => 'Invalid API key']);

        return;
    }
    $req = json_decode($body, true) ?: [];
    $rate = $rates[$m[1]] ?? null;
    if ($rate === null || empty($req['amount'])) {
        respond(200, ['status' => 'error', 'message' => 'bad quote request']);

        return;
    }
    respond(200, ['status' => 'success', 'crypto_amount' => number_format((float) $req['amount'] / (float) $rate, 8, '.', ''), 'exchange_rate' => $rate]);

    return;
}

if ($method === 'POST' && preg_match('#^/api/v1/([A-Za-z0-9_-]+)/payout$#', $path, $m)) {
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (! hash_equals('Basic '.base64_encode($payoutUser.':'.$payoutPassword), $auth)) {
        respond(401, ['status' => 'error', 'msg' => 'Basic auth failed']);

        return;
    }
    $req = json_decode($body, true) ?: [];
    foreach (['amount', 'destination', 'fee'] as $field) {
        if (! isset($req[$field]) || $req[$field] === '') {
            respond(400, ['status' => 'error', 'msg' => "missing {$field}"]);

            return;
        }
    }
    $stateLock = lockState($stateFile);
    $state = load($stateFile);
    $externalId = (string) ($req['external_id'] ?? bin2hex(random_bytes(6)));
    $taskId = bin2hex(random_bytes(8));
    $state['payouts'][$externalId] = [
        'task_id' => $taskId, 'external_id' => $externalId, 'crypto' => $m[1], 'amount' => (string) $req['amount'],
        'destination' => $req['destination'], 'status' => 'IN_PROGRESS', 'txid' => null, 'callback_url' => $req['callback_url'] ?? null,
    ];
    save($stateFile, $state);
    unlockState($stateLock);
    respond(200, ['task_id' => $taskId, 'external_id' => $externalId]);

    return;
}

if ($method === 'GET' && preg_match('#^/api/v1/([A-Za-z0-9_-]+)/payout/status$#', $path, $m)) {
    if (! authorised($apiKey)) {
        respond(401, ['status' => 'error', 'message' => 'Invalid API key']);

        return;
    }
    $payout = load($stateFile)['payouts'][(string) ($_GET['external_id'] ?? '')] ?? null;
    if ($payout === null) {
        respond(404, ['status' => 'error', 'message' => 'payout not found']);

        return;
    }
    respond(200, ['id' => 1, 'external_id' => $payout['external_id'], 'crypto' => $payout['crypto'], 'status' => $payout['status'],
        'amount' => $payout['amount'], 'destination' => $payout['destination'], 'txid' => $payout['txid']]);

    return;
}

if ($method === 'POST' && preg_match('#^/__mock/payout-result/([A-Za-z0-9_-]+)$#', $path, $m)) {
    $stateLock = lockState($stateFile);
    $state = load($stateFile);
    $payout = $state['payouts'][$m[1]] ?? null;
    if ($payout === null) {
        respond(404, ['status' => 'error', 'message' => 'unknown payout']);

        return;
    }
    $req = json_decode($body, true) ?: [];
    $payout['status'] = ($req['status'] ?? 'SUCCESS') === 'FAIL' ? 'FAIL' : 'SUCCESS';
    $payout['txid'] = $payout['status'] === 'SUCCESS' ? bin2hex(random_bytes(32)) : null;
    $state['payouts'][$m[1]] = $payout;
    save($stateFile, $state);
    unlockState($stateLock);

    $code = null;
    if ($payout['callback_url']) {
        $payload = json_encode([
            'payout_id' => 1, 'external_id' => $payout['external_id'], 'tx_hash' => $payout['txid'], 'status' => $payout['status'],
            'amount' => $payout['amount'], 'crypto' => $payout['crypto'], 'timestamp' => time(),
        ], JSON_UNESCAPED_SLASHES);
        [$code] = postCallback($payout['callback_url'], $payload, $apiKey, true, false);
    }
    respond(200, ['status' => 'success', 'payout_status' => $payout['status'], 'callback_http_status' => $code]);

    return;
}

if ($method === 'POST' && $path === '/__mock/callback-unknown') {
    $req = json_decode($body, true) ?: [];
    foreach (['external_id', 'amount', 'fiat', 'callback_url'] as $field) {
        if (empty($req[$field])) {
            respond(422, ['status' => 'error', 'message' => "missing {$field}"]);

            return;
        }
    }
    $crypto = (string) ($req['crypto'] ?? 'BTC');
    $amount = (string) $req['amount'];
    // Synthetic invoice that is never stored: the shop sees an id the mock never issued.
    $invoice = [
        'external_id' => (string) $req['external_id'],
        'crypto' => $crypto,
        'wallet' => 'mock'.strtolower($crypto).substr(hash('sha256', $req['external_id'].$crypto), 0, 30),
        'status' => (string) ($req['status'] ?? 'PAID'),
        'txs' => [['txid' => bin2hex(random_bytes(16)), 'amount_fiat' => $amount, 'trigger' => true]],
    ];
    [$code, $response] = postCallback((string) $req['callback_url'], invoicePayload($invoice, (string) $req['fiat'], $amount), $apiKey);
    respond(200, ['status' => 'success', 'callback_http_status' => $code, 'callback_response' => json_decode($response, true)]);

    return;
}

if ($method === 'POST' && $path === '/__mock/send-raw') {
    $req = json_decode($body, true) ?: [];
    if (empty($req['callback_url']) || ! isset($req['body']) || ! is_string($req['body'])) {
        respond(422, ['status' => 'error', 'message' => 'callback_url and string body are required']);

        return;
    }
    [$code, $response] = postCallback((string) $req['callback_url'], $req['body'], $apiKey, ($req['sign'] ?? true) !== false);
    respond(200, ['status' => 'success', 'http_status' => $code, 'response_body' => $response]);

    return;
}

if ($path === '/__mock/config') {
    if ($method === 'GET') {
        respond(200, ['status' => 'success', 'config' => mockConfig(load($stateFile))]);

        return;
    }
    if ($method === 'POST') {
        $req = json_decode($body, true);
        if (! is_array($req)) {
            respond(422, ['status' => 'error', 'message' => 'body must be a JSON object']);

            return;
        }
        $rules = [];
        foreach ((array) ($req['fail_next'] ?? []) as $rule) {
            if (! is_array($rule) || empty($rule['match']) || ! in_array($rule['mode'] ?? null, FAIL_MODES, true)) {
                respond(422, ['status' => 'error', 'message' => 'each fail_next entry needs match and mode ('.implode('|', FAIL_MODES).')']);

                return;
            }
            $clean = ['match' => (string) $rule['match'], 'mode' => $rule['mode'], 'count' => max(1, (int) ($rule['count'] ?? 1))];
            if (isset($rule['seconds'])) {
                $clean['seconds'] = max(0, (int) $rule['seconds']);
            }
            $rules[] = $clean;
        }
        $stateLock = lockState($stateFile);
    $state = load($stateFile);
        $state[CONFIG_KEY] = ['delay_ms' => max(0, (int) ($req['delay_ms'] ?? 0)), 'fail_next' => $rules];
        save($stateFile, $state);
        unlockState($stateLock);
        respond(200, ['status' => 'success', 'config' => $state[CONFIG_KEY]]);

        return;
    }
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
