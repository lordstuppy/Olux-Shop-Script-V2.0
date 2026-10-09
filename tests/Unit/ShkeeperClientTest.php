<?php

namespace Tests\Unit;

use App\Exceptions\ShkeeperException;
use App\Services\Shkeeper\ShkeeperClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Tests\TestCase;

class ShkeeperClientTest extends TestCase
{
    private Factory $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new Factory;
    }

    private function client(?string $secret = 'test-webhook-secret'): ShkeeperClient
    {
        return new ShkeeperClient($this->http, 'https://pay.example', 'api-key-123', $secret, 300, 5);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__).'/Fixtures/shkeeper/'.$name);
    }

    public function test_create_invoice_sends_expected_request(): void
    {
        $this->http->fake(['pay.example/*' => $this->http->response(json_decode($this->fixture('payment_request.json'), true))]);

        $invoice = $this->client()->createInvoice('BTC', 'order-uuid', 2500, 'EUR', 'https://shop.example/webhooks/shkeeper');

        $this->http->assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://pay.example/api/v1/BTC/payment_request'
                && $request->header('X-Shkeeper-Api-Key') === ['api-key-123']
                && $request->data() === [
                    'external_id' => 'order-uuid',
                    'fiat' => 'EUR',
                    'amount' => '25.00',
                    'callback_url' => 'https://shop.example/webhooks/shkeeper',
                ];
        });
        $this->assertSame('77', $invoice->id);
        $this->assertSame('bc1qfixturewalletaddress0000000000000000', $invoice->wallet);
        $this->assertSame('0.0004166', $invoice->cryptoAmount);
    }

    public function test_create_invoice_surfaces_provider_errors(): void
    {
        $this->http->fake(['pay.example/*' => $this->http->response(['status' => 'error', 'message' => 'BTC payment gateway is unavailable'])]);

        $this->expectException(ShkeeperException::class);
        $this->expectExceptionMessage('BTC payment gateway is unavailable');
        $this->client()->createInvoice('BTC', 'order-uuid', 2500, 'EUR', 'https://shop.example/cb');
    }

    public function test_create_invoice_rejects_http_failures_and_bad_crypto_names(): void
    {
        $this->http->fake(['pay.example/*' => $this->http->response('nope', 500)]);
        try {
            $this->client()->createInvoice('BTC', 'x', 100, 'USD', 'https://shop.example/cb');
            $this->fail('Expected exception for HTTP 500');
        } catch (ShkeeperException $e) {
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
        }

        $this->expectException(ShkeeperException::class);
        $this->client()->createInvoice('../admin', 'x', 100, 'USD', 'https://shop.example/cb');
    }

    public function test_get_invoice_status_parses_fixture(): void
    {
        $this->http->fake(['pay.example/api/v1/invoices/*' => $this->http->response(json_decode($this->fixture('invoice_status.json'), true))]);

        $status = $this->client()->getInvoiceStatus('6f1b0e0c-1d2a-4b47-9a5e-2f0e2b8c9a10');

        $this->assertTrue($status->isPaid());
        $this->assertSame('25.00', $status->balanceFiat);
        $this->assertSame('EUR', $status->fiat);
        $this->http->assertSent(fn (Request $r) => $r->url() === 'https://pay.example/api/v1/invoices/6f1b0e0c-1d2a-4b47-9a5e-2f0e2b8c9a10');
    }

    public function test_get_invoice_status_returns_null_when_unknown(): void
    {
        $this->http->fake(['pay.example/*' => $this->http->response(['invoices' => [], 'status' => 'success'])]);
        $this->assertNull($this->client()->getInvoiceStatus('missing'));
    }

    public function test_verifies_signature_from_fixture(): void
    {
        $body = $this->fixture('callback.json');
        $meta = json_decode($this->fixture('callback.signature.json'), true);
        $client = $this->client($meta['secret']);
        $now = (int) $meta['timestamp'] + 10;

        $this->assertTrue($client->verifyWebhookSignature($body, $meta['signature'], $meta['timestamp'], $now));
        $this->assertTrue($client->verifyWebhookSignature($body, strtoupper($meta['signature']), $meta['timestamp'], $now));
    }

    public function test_rejects_tampered_body_wrong_secret_and_missing_headers(): void
    {
        $body = $this->fixture('callback.json');
        $meta = json_decode($this->fixture('callback.signature.json'), true);
        $now = (int) $meta['timestamp'];

        $tampered = str_replace('"25.00"', '"2500.00"', $body);
        $this->assertFalse($this->client($meta['secret'])->verifyWebhookSignature($tampered, $meta['signature'], $meta['timestamp'], $now));
        $this->assertFalse($this->client('other-secret')->verifyWebhookSignature($body, $meta['signature'], $meta['timestamp'], $now));
        $this->assertFalse($this->client($meta['secret'])->verifyWebhookSignature($body, null, $meta['timestamp'], $now));
        $this->assertFalse($this->client($meta['secret'])->verifyWebhookSignature($body, $meta['signature'], null, $now));
        $this->assertFalse($this->client($meta['secret'])->verifyWebhookSignature($body, $meta['signature'], 'abc', $now));
    }

    public function test_rejects_stale_timestamp_and_missing_secret(): void
    {
        $body = $this->fixture('callback.json');
        $meta = json_decode($this->fixture('callback.signature.json'), true);

        $this->assertFalse($this->client($meta['secret'])->verifyWebhookSignature($body, $meta['signature'], $meta['timestamp'], (int) $meta['timestamp'] + 301));
        $this->assertFalse($this->client(null)->verifyWebhookSignature($body, $meta['signature'], $meta['timestamp'], (int) $meta['timestamp']));
        $this->assertFalse($this->client('')->verifyWebhookSignature($body, $meta['signature'], $meta['timestamp'], (int) $meta['timestamp']));
    }
}
