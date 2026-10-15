<?php

/*
 * Contract test of the shop's Shkeeper client against a real Shkeeper.
 * Moves no money: it lists cryptos, creates invoices (addresses that nobody
 * pays), reads their status, asks for quotes and probes error answers. It
 * never calls the payout endpoint.
 *
 *   SHKEEPER_CONTRACT_URL=https://demo.shkeeper.io SHKEEPER_CONTRACT_KEY=... \
 *       php tests/shkeeper/contract.php [crypto] [out.json]
 *
 * Writes one result per check (PASS, FAIL or INFO) to out.json and the console;
 * exit code 1 if a check fails.
 */

use App\Exceptions\ShkeeperException;
use App\Services\Shkeeper\ShkeeperClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = rtrim((string) getenv('SHKEEPER_CONTRACT_URL'), '/');
$key = (string) getenv('SHKEEPER_CONTRACT_KEY');
$crypto = $argv[1] ?? 'LTC';
$out = $argv[2] ?? null;
if ($base === '' || $key === '') {
    fwrite(STDERR, "Set SHKEEPER_CONTRACT_URL and SHKEEPER_CONTRACT_KEY.\n");
    exit(2);
}

$results = [];
$check = function (string $name, string $status, string $detail) use (&$results): void {
    $results[] = ['check' => $name, 'status' => $status, 'detail' => $detail];
    echo str_pad($status, 5).' '.$name.': '.$detail."\n";
};
$client = fn (string $apiKey = '') => new ShkeeperClient(app(Factory::class), $base, $apiKey !== '' ? $apiKey : $key, 'unused', 300, 15);
$raw = fn (string $method, string $path, ?array $json = null, string $apiKey = '') => Http::acceptJson()->timeout(15)
    ->withHeaders(['X-Shkeeper-Api-Key' => $apiKey !== '' ? $apiKey : $key])
    ->send($method, $base.$path, $json === null ? [] : ['json' => $json]);
$callback = 'https://shop.invalid/webhooks/shkeeper';

// 1. Cryptos
try {
    $list = $client()->availableCryptos();
    $names = array_column($list, 'name');
    $check('crypto list parsed', in_array($crypto, $names, true) ? 'PASS' : 'FAIL', implode(', ', $names));
} catch (Throwable $e) {
    $check('crypto list parsed', 'FAIL', $e->getMessage());
}

// 2. Invoice creation, as the checkout does
$externalId = (string) Str::uuid();
try {
    $invoice = $client()->createInvoice($crypto, $externalId, 1234, 'USD', $callback);
    $check('invoice created', $invoice->wallet !== '' && $invoice->cryptoAmount !== '' ? 'PASS' : 'FAIL',
        "id {$invoice->id}, amount {$invoice->cryptoAmount} {$crypto}, rate {$invoice->exchangeRate}, address ".substr($invoice->wallet, 0, 6).'...');
    $again = $client()->createInvoice($crypto, $externalId, 1234, 'USD', $callback);
    $check('same order id returns the same invoice (retry after a lost answer)', $again->id === $invoice->id && $again->wallet === $invoice->wallet ? 'PASS' : 'FAIL',
        "first id {$invoice->id}, second id {$again->id}");
} catch (Throwable $e) {
    $check('invoice created', 'FAIL', $e->getMessage());
}

// 3. Invoice status, as the 5-minute reconciliation reads it
try {
    $status = $client()->getInvoiceStatus($externalId);
    $check('invoice status parsed', $status !== null && $status->status === 'UNPAID' && $status->fiat === 'USD' && (float) $status->amountFiat === 12.34 ? 'PASS' : 'FAIL',
        $status === null ? 'no invoice found' : "status {$status->status}, fiat {$status->fiat}, amount {$status->amountFiat}, balance {$status->balanceFiat}");
    $fields = $raw('GET', '/api/v1/invoices/'.$externalId)->json('invoices.0');
    $check('invoice status fields', 'INFO', 'keys: '.implode(', ', array_keys((array) $fields)));
    $unknown = $client()->getInvoiceStatus((string) Str::uuid());
    $check('unknown invoice reads as "no invoice"', $unknown === null ? 'PASS' : 'FAIL', $unknown === null ? 'null' : 'returned an invoice');
} catch (Throwable $e) {
    $check('invoice status parsed', 'FAIL', $e->getMessage());
}

// 4. Quote, as the payment page and payouts use it
$quoteRaw = $raw('POST', "/api/v1/{$crypto}/quote", ['fiat' => 'USD', 'amount' => '12.34']);
$check('quote answer', 'INFO', 'HTTP '.$quoteRaw->status().' keys: '.implode(', ', array_keys((array) $quoteRaw->json())));
try {
    $amount = $client()->quote($crypto, 1234, 'USD');
    $check('quote parsed by the shop', 'PASS', "{$amount} {$crypto} for 12.34 USD");
} catch (Throwable $e) {
    $check('quote parsed by the shop', 'FAIL', $e->getMessage());
}

// 5. Error answers
try {
    $client('wrong-key-'.Str::random(8))->createInvoice($crypto, (string) Str::uuid(), 1000, 'USD', $callback);
    $check('wrong API key refused', 'FAIL', 'invoice created with a wrong key');
} catch (ShkeeperException $e) {
    $check('wrong API key refused', 'PASS', $e->getMessage());
}
try {
    $client()->createInvoice('NOPE', (string) Str::uuid(), 1000, 'USD', $callback);
    $check('unknown crypto refused', 'FAIL', 'invoice created');
} catch (ShkeeperException $e) {
    $check('unknown crypto refused', 'PASS', $e->getMessage());
}
try {
    $eur = $client()->createInvoice($crypto, (string) Str::uuid(), 1000, 'EUR', $callback);
    $check('EUR invoice', 'PASS', "amount {$eur->cryptoAmount} {$crypto}");
} catch (Throwable $e) {
    $check('EUR invoice', 'INFO', 'refused: '.$e->getMessage());
}

// 6. Payout status of a payout Shkeeper never saw (read only; no payout is created)
$probe = $raw('GET', "/api/v1/{$crypto}/payout/status?external_id=payout-contract-".Str::random(6));
$check('unknown payout: raw answer', 'INFO', 'HTTP '.$probe->status().' '.mb_substr($probe->body(), 0, 120));
try {
    $result = $client()->payoutStatus($crypto, 'payout-contract-'.Str::random(6));
    $check('unknown payout reads as "never arrived"', $result === null ? 'PASS' : 'FAIL', json_encode($result));
} catch (Throwable $e) {
    $check('unknown payout reads as "never arrived"', 'FAIL', $e->getMessage());
}

if ($out !== null) {
    file_put_contents($out, json_encode(['shkeeper' => $base, 'crypto' => $crypto, 'run_at' => gmdate('c'), 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}
exit(in_array('FAIL', array_column($results, 'status'), true) ? 1 : 0);
