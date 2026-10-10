<?php

namespace Tests\Feature;

use App\Mail\LoginLockedMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LoginLockTest extends TestCase
{
    private function attempt(string $email, string $password, string $ip): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postForm('/login', ['email' => $email, 'password' => $password]);
    }

    public function test_twenty_failures_from_many_addresses_lock_the_account_until_a_password_reset(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'target@example.test']);
        for ($i = 0; $i < 20; $i++) {
            $this->attempt('target@example.test', 'wrong-password-'.$i, '203.0.113.'.intdiv($i, 4))
                ->assertSessionHas('error', 'Login failed: the email or password is incorrect.');
        }

        $this->attempt('target@example.test', 'correct-horse-battery-1', '198.51.100.9')
            ->assertSessionHas('error', 'Sign-in to this account is paused for 15 minutes after too many failed attempts. Reset your password to sign in now.');
        $this->assertGuest();
        Mail::assertQueued(LoginLockedMail::class, 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'user.login_locked')->count());

        // More failures while locked do not send more mail.
        $this->attempt('target@example.test', 'wrong-again', '198.51.100.10');
        Mail::assertQueued(LoginLockedMail::class, 1);

        $token = Password::createToken($user);
        $this->postForm('/reset-password', ['token' => $token, 'email' => 'target@example.test', 'password' => 'A-new-password-2026', 'password_confirmation' => 'A-new-password-2026'])
            ->assertRedirect();
        $this->attempt('target@example.test', 'A-new-password-2026', '198.51.100.11')->assertRedirect(route('account.orders'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_unknown_emails_lock_the_same_way_and_send_nothing(): void
    {
        Mail::fake();
        for ($i = 0; $i < 20; $i++) {
            $this->attempt('ghost@example.test', 'nope-'.$i, '203.0.113.'.intdiv($i, 4));
        }
        $this->attempt('ghost@example.test', 'nope', '198.51.100.9')->assertSessionHas('error', fn ($m) => str_contains($m, 'paused for 15 minutes'));
        Mail::assertNothingQueued();
    }

    public function test_a_successful_sign_in_resets_the_failure_count(): void
    {
        User::factory()->create(['email' => 'typo@example.test']);
        for ($round = 0; $round < 2; $round++) {
            for ($i = 0; $i < 15; $i++) {
                $this->attempt('typo@example.test', 'typo-'.$i, '203.0.113.'.($round * 10 + intdiv($i, 4)));
            }
            $this->attempt('typo@example.test', 'correct-horse-battery-1', '198.51.100.'.$round)->assertRedirect(route('account.orders'));
            $this->postForm('/logout');
        }
    }
}
