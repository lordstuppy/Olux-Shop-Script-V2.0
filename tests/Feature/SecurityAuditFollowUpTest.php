<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\UserFacingException;
use App\Models\GiftCard;
use App\Models\User;
use App\Notifications\ResetPasswordQueued;
use App\Services\TwoFactorService;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Regression tests for the findings of the security-audit skill run
 * (selfdealing-bypass-giftcard-self-mint-redeem and
 * unverified-account-2fa-enrollment-survives-owner-reset).
 */
class SecurityAuditFollowUpTest extends TestCase
{
    public function test_staff_cannot_redeem_a_gift_card_they_issued(): void
    {
        $finance = User::factory()->staff(UserRole::Finance)->create();
        $this->actingAs($finance)->confirmPassword();
        $this->postForm(route('admin.gift-cards.store'), ['_once' => (string) Str::uuid(), 'amount' => '500.00', 'currency' => 'USD'])
            ->assertSessionHas('success');
        $this->assertMatchesRegularExpression('/Code: ([A-Z0-9]{4}(?:-[A-Z0-9]{4})+)/', (string) session('success'));
        preg_match('/Code: ([A-Z0-9]{4}(?:-[A-Z0-9]{4})+)/', (string) session('success'), $m);

        // Like a balance adjustment on one's own account, this needs another person.
        $this->postForm(route('wallet.redeem'), ['code' => $m[1]])
            ->assertSessionHas('error', 'This concerns your own account, order, sale or payout. Another team member has to do it.');
        $this->assertSame(0, $finance->fresh()->balance_minor);
        $this->assertNull(GiftCard::query()->sole()->redeemed_at);

        // Anyone else can still redeem it.
        $buyer = User::factory()->create();
        $this->actingAs($buyer)->postForm(route('wallet.redeem'), ['code' => $m[1]])->assertSessionHas('success');
        $this->assertSame(50000, $buyer->fresh()->balance_minor);
    }

    public function test_two_factor_needs_a_confirmed_email_address(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);

        $this->get(route('account.two-factor'))->assertOk()
            ->assertSee('Confirm your email address first, then turn on two-factor authentication.')
            ->assertDontSee('data:image/svg+xml;base64', false);
        $this->assertNull(session('two_factor.setup_secret'));
        $this->postForm(route('account.two-factor.enable'), ['code' => '123456', 'current_password' => 'correct-horse-battery-1'])
            ->assertRedirect(route('verification.notice'));
        $this->assertFalse($user->fresh()->hasTwoFactor());

        // The service refuses as well, for any other caller.
        $secret = app(TwoFactorService::class)->generateSecret();
        $this->expectException(UserFacingException::class);
        app(TwoFactorService::class)->enable($user, $secret, (new Google2FA)->getCurrentOtp($secret));
    }

    public function test_reset_of_a_never_confirmed_account_drops_a_second_factor_set_by_the_registrant(): void
    {
        Notification::fake();
        // An account registered by someone else, who set up 2FA before the fix.
        $squatted = User::factory()->unverified()->withTwoFactor()->create(['email' => 'owner@example.test']);
        $this->assertTrue($squatted->hasTwoFactor());

        $this->resetPassword('owner@example.test', 'owner-new-pass-123');

        $owner = $squatted->fresh();
        $this->assertFalse($owner->hasTwoFactor());
        $this->assertNull($owner->two_factor_recovery_codes);
        $this->assertNotNull($owner->email_verified_at, 'the reset link proves the mailbox');
        $this->postForm('/login', ['email' => 'owner@example.test', 'password' => 'owner-new-pass-123'])
            ->assertRedirect(route('account.orders'));
        $this->assertAuthenticatedAs($owner);
    }

    public function test_reset_of_a_confirmed_account_keeps_its_second_factor(): void
    {
        Notification::fake();
        $user = User::factory()->withTwoFactor()->create(['email' => 'careful@example.test']);

        $this->resetPassword('careful@example.test', 'careful-new-pass-123');

        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->assertSame(UserFactory::TWO_FACTOR_SECRET, $user->fresh()->two_factor_secret);
        $this->postForm('/login', ['email' => 'careful@example.test', 'password' => 'careful-new-pass-123'])
            ->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
    }

    private function resetPassword(string $email, string $password): void
    {
        $this->postForm('/forgot-password', ['email' => $email])->assertSessionHas('success');
        $token = null;
        Notification::assertSentTo(User::query()->where('email', $email)->sole(), ResetPasswordQueued::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->postForm('/reset-password', ['token' => $token, 'email' => $email, 'password' => $password, 'password_confirmation' => $password])
            ->assertRedirect(route('login'));
    }
}
