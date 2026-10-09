<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    public function test_form_without_csrf_token_is_rejected(): void
    {
        $product = Product::factory()->create();
        $this->post('/cart/items', ['product_id' => $product->id])->assertStatus(419);

        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery-1'])->assertStatus(419);
        $this->assertGuest();
    }

    public function test_form_with_wrong_csrf_token_is_rejected(): void
    {
        $this->csrfToken();
        $this->post('/cart/currency', ['currency' => 'EUR', '_token' => 'forged'])->assertStatus(419);
    }

    public function test_security_headers_are_set(): void
    {
        $response = $this->get('/');
        $response->assertOk();
        $response->assertHeader('Content-Security-Policy', SecurityHeaders::CSP);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $this->assertNotEmpty($response->headers->get('Permissions-Policy'));
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
        $this->assertStringNotContainsString('unsafe-inline', SecurityHeaders::CSP);
    }

    public function test_hsts_on_https(): void
    {
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security');
    }

    public function test_session_cookie_flags(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
        $this->assertSame('argon2id', config('hashing.driver'));
        $this->assertStringStartsWith('$argon2id$', User::factory()->create()->password_hash);
    }

    public function test_errors_show_request_id_but_no_stack_trace(): void
    {
        Route::middleware('web')->get('/_test/boom', fn () => throw new RuntimeException('secret internal detail'));

        $response = $this->get('/_test/boom');
        $response->assertStatus(500);
        $response->assertSee('Something went wrong');
        $response->assertSee($response->headers->get('X-Request-Id'));
        $response->assertDontSee('secret internal detail');
        $response->assertDontSee('RuntimeException');
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->postForm('/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertRedirect();
        }
        $this->postForm('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(429)->assertSee('Too many login attempts for this email');
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postForm('/register', ['name' => 'x', 'email' => "u{$i}@example.test", 'password' => 'short', 'password_confirmation' => 'short', 'form_token' => $this->formToken()]);
        }
        $this->postForm('/register', ['name' => 'x', 'email' => 'u99@example.test'])->assertStatus(429);
    }

    public function test_webhook_is_rate_limited(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->postJson('/webhooks/shkeeper', [])->assertStatus(401);
        }
        $this->postJson('/webhooks/shkeeper', [])->assertStatus(429);
    }

    public function test_output_is_escaped(): void
    {
        $product = Product::factory()->create(['title' => '<script>alert(1)</script>', 'description' => '<img src=x onerror=alert(1)>']);

        $this->get(route('products.show', $product->slug))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        $user = User::factory()->suspended()->create();
        $this->postForm('/login', ['email' => $user->email, 'password' => 'correct-horse-battery-1'])->assertRedirect();
        $this->assertGuest();
        $this->assertStringContainsString('suspended', session('error'));
    }

    public function test_product_files_are_not_publicly_reachable(): void
    {
        $product = $this->instantProductWithFile();
        $this->get('/storage/products/'.$product->id.'/tool.zip')->assertNotFound();
    }
}
