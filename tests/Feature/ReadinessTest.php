<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadinessTest extends TestCase
{
    public function test_the_colour_scheme_follows_the_device_until_the_visitor_chooses_one(): void
    {
        $this->get('/')->assertOk()->assertDontSee('data-theme=', false)->assertSee('name="theme"', false);

        $response = $this->from('/products')->postForm('/theme', ['theme' => 'dark']);
        $response->assertRedirect('/products')->assertCookie('theme', 'dark');

        $this->withCookie('theme', 'dark')->get('/')->assertSee('data-theme="dark"', false);
        $this->withCookie('theme', 'light')->get('/login')->assertSee('data-theme="light"', false);
        $this->withCookie('theme', '"><script>')->get('/')->assertDontSee('data-theme=', false);

        $this->postForm('/theme', ['theme' => 'system'])->assertCookieExpired('theme');
        $this->postForm('/theme', ['theme' => 'neon'])->assertSessionHasErrors('theme');
    }

    public function test_security_txt_names_the_security_contact(): void
    {
        config(['shop.security_email' => 'security@shop.test']);

        $this->get('/.well-known/security.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Contact: mailto:security@shop.test')->assertSee('Expires: '.now()->addYear()->format('Y-m-d'));
    }

    public function test_stylesheet_and_script_urls_carry_a_content_hash(): void
    {
        $hash = substr(hash_file('xxh3', public_path('css/app.css')), 0, 12);

        $this->get('/')->assertSee('css/app.css?v='.$hash, false)->assertSee('js/enhance.js?v=', false);
    }

    public function test_html_pages_are_not_cached_by_browsers_or_proxies(): void
    {
        $cacheControl = (string) $this->get('/products')->headers->get('Cache-Control');

        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    /**
     * Every foreign key the application queries by has an index (PostgreSQL
     * does not create them). New foreign keys must be indexed or added here
     * with a reason.
     */
    public function test_foreign_keys_are_indexed(): void
    {
        // Columns that only record which staff member did something; nothing filters by them.
        $unindexedOnPurpose = [
            'announcements.created_by', 'coupons.created_by', 'dispute_messages.author_id', 'disputes.refund_payment_id',
            'disputes.resolved_by', 'email_templates.updated_by', 'exchange_rates.updated_by', 'gift_cards.created_by',
            'payments.created_by', 'payouts.processed_by', 'product_reviews.moderated_by', 'seller_profiles.reviewed_by',
            'settings.updated_by', 'ticket_messages.author_id', 'tickets.assigned_to',
        ];

        $missing = collect(DB::select("
            select c.conrelid::regclass::text || '.' || a.attname as col
            from pg_constraint c
            join pg_attribute a on a.attrelid = c.conrelid and a.attnum = c.conkey[1]
            where c.contype = 'f' and array_length(c.conkey, 1) = 1
              and not exists (select 1 from pg_index i where i.indrelid = c.conrelid and i.indkey[0] = c.conkey[1])
        "))->pluck('col')->diff($unindexedOnPurpose)->values()->all();

        $this->assertSame([], $missing, 'Foreign keys without an index: '.implode(', ', $missing));
    }
}
