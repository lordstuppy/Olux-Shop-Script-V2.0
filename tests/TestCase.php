<?php

namespace Tests;

use App\Models\Product;
use App\Models\User;
use App\Services\Shkeeper\ShkeeperClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('products');
        Storage::fake('invoices');
    }

    /** CSRF is enforced in tests too; forms must carry the session token. */
    protected function csrfToken(): string
    {
        $this->startSession();

        return session()->token();
    }

    protected function postForm(string $uri, array $data = []): TestResponse
    {
        return $this->post($uri, $data + ['_token' => $this->csrfToken()]);
    }

    protected function putForm(string $uri, array $data = []): TestResponse
    {
        return $this->put($uri, $data + ['_token' => $this->csrfToken()]);
    }

    protected function deleteForm(string $uri, array $data = []): TestResponse
    {
        return $this->delete($uri, $data + ['_token' => $this->csrfToken()]);
    }

    /** Marks the password as recently confirmed, as the confirm-password page would. */
    protected function confirmPassword(): static
    {
        return $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    }

    /** A valid registration form token (rendered 10 seconds ago). */
    protected function formToken(): string
    {
        return Crypt::encryptString((string) (time() - 10));
    }

    /** Sends a webhook signed the way Shkeeper signs it. */
    protected function postShkeeperWebhook(array $payload, ?string $secret = null, ?int $timestamp = null, ?string $raw = null): TestResponse
    {
        $raw ??= json_encode($payload, JSON_UNESCAPED_SLASHES);
        $headers = ShkeeperClient::signatureHeaders($raw, $secret ?? 'test-webhook-secret', $timestamp ?? time());

        return $this->call('POST', '/webhooks/shkeeper', [], [], [], $this->transformHeadersToServerVars($headers + [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]), $raw);
    }

    protected function paidPayload(string $publicId, string $amount, string $fiat = 'USD', array $extra = []): array
    {
        return array_merge([
            'external_id' => $publicId,
            'crypto' => 'BTC',
            'addr' => 'bc1qtestaddress',
            'fiat' => $fiat,
            'balance_fiat' => $amount,
            'balance_crypto' => '0.00041',
            'paid' => true,
            'status' => 'PAID',
            'transactions' => [['txid' => 'tx-1', 'amount_fiat' => $amount, 'trigger' => true]],
            'fee_percent' => '0',
            'overpaid_fiat' => '0.00',
        ], $extra);
    }

    /** Fakes the Shkeeper API endpoints used at checkout. */
    protected function fakeShkeeper(string $invoiceId = '77'): void
    {
        Http::fake([
            'shkeeper.test/api/v1/crypto' => Http::response(json_decode((string) file_get_contents(__DIR__.'/Fixtures/shkeeper/crypto.json'), true)),
            'shkeeper.test/api/v1/*/payment_request' => function (HttpRequest $request) use ($invoiceId) {
                $fixture = json_decode((string) file_get_contents(__DIR__.'/Fixtures/shkeeper/payment_request.json'), true);
                $fixture['id'] = (int) $invoiceId;

                return Http::response($fixture);
            },
        ]);
    }

    protected function instantProductWithFile(array $attributes = []): Product
    {
        $product = Product::factory()->create($attributes);
        Storage::disk('products')->put($product->id.'/tool.zip', 'zip-bytes');
        $product->files()->create([
            'original_name' => 'tool.zip',
            'storage_path' => $product->id.'/tool.zip',
            'checksum' => hash('sha256', 'zip-bytes'),
            'size' => 9,
            'scan_status' => 'clean',
        ]);

        return $product;
    }

    protected function buyer(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
