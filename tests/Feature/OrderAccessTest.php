<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OrderService;
use Tests\TestCase;

class OrderAccessTest extends TestCase
{
    public function test_other_users_order_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $product = $this->instantProductWithFile();
        $order = app(OrderService::class)->createFromCart($owner, [$product->id => 1], 'USD', 'key-0000000000000001');

        $intruder = User::factory()->create();
        $this->actingAs($intruder);

        $this->get(route('orders.show', $order))->assertForbidden();
        $this->get(route('orders.pay', $order))->assertForbidden();
        $this->get(route('orders.result', $order))->assertForbidden();
        $this->get(route('orders.invoice', $order))->assertForbidden();
        $this->postForm(route('orders.cancel', $order))->assertForbidden();
        $this->postForm(route('orders.pay.balance', $order))->assertForbidden();
    }

    public function test_signed_download_link_is_useless_to_another_user(): void
    {
        $owner = User::factory()->create();
        $product = $this->instantProductWithFile();
        $order = app(OrderService::class)->createFromCart($owner, [$product->id => 1], 'USD', 'key-0000000000000001');
        $item = $order->items->first();
        $item->update(['delivered_payload' => ['type' => 'instant', 'files' => [['id' => $product->files->first()->id]]], 'delivered_at' => now()]);
        $order->update(['status' => 'paid']);

        $url = app(\App\Services\DeliveryService::class)->downloadUrl($order, $item, $product->files->first());

        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs($owner)->get($url)->assertOk();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $order = app(OrderService::class)->createFromCart(User::factory()->create(), [$this->instantProductWithFile()->id => 1], 'USD', 'key-0000000000000001');
        $this->get(route('orders.show', $order))->assertRedirect(route('login'));
    }

    public function test_admin_may_view_but_not_pay(): void
    {
        $order = app(OrderService::class)->createFromCart(User::factory()->create(), [$this->instantProductWithFile()->id => 1], 'USD', 'key-0000000000000001');
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('orders.show', $order))->assertOk();
        $this->get(route('orders.pay', $order))->assertForbidden();
    }

    public function test_buyer_cannot_reach_seller_or_admin_areas(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/admin')->assertForbidden();
        $this->get('/admin/audit')->assertForbidden();
        $this->get('/seller')->assertForbidden();
    }
}
