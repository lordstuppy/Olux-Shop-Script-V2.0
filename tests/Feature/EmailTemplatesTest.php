<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\OrderPaidMail;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Support\EmailTemplates;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

class EmailTemplatesTest extends TestCase
{
    private function paidOrder(): Order
    {
        $product = $this->instantProductWithFile(['price_minor' => 1999, 'title' => 'Icon pack']);
        $buyer = User::factory()->withBalance(5000)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);

        return Order::with('items', 'buyer')->find($order->id);
    }

    public function test_every_definition_matches_its_mailable(): void
    {
        foreach (EmailTemplates::definitions() as $key => $definition) {
            $class = $definition['class'];
            $this->assertSame($key, $class::templateKey(), $class);
            // The data a mail provides is exactly the documented placeholder set.
            $method = (new ReflectionClass($class))->getMethod('templateData');
            $this->assertSame($class, $method->getDeclaringClass()->getName());
            $this->assertSame([], EmailTemplates::problems($key, EmailTemplates::starter($key)['subject'], EmailTemplates::starter($key)['body']), $key.' starter text');
            $this->assertSame(array_keys($definition['placeholders']), array_keys(array_diff_key(EmailTemplates::sample($key), EmailTemplates::GLOBALS)), $key.' sample values');
        }
        $order = $this->paidOrder();
        $this->assertSame(array_keys(EmailTemplates::definitions()['order_paid']['placeholders']), array_keys((new OrderPaidMail($order))->templateData()));
    }

    public function test_edited_text_is_used_for_sending_and_can_be_reset(): void
    {
        $order = $this->paidOrder();
        $default = new OrderPaidMail($order);
        $this->assertSame('Order '.$order->shortId().' paid', $default->envelope()->subject);

        $this->actingAs(User::factory()->staff(UserRole::Manager)->create());
        $this->get(route('admin.email-templates.index'))->assertOk()->assertSee('Order paid')->assertSee('Built-in');
        $this->get(route('admin.email-templates.edit', 'order_paid'))->assertOk()->assertSee('{order_url}')->assertSee('Download links and licence keys: {order_url}');

        $this->putForm(route('admin.email-templates.update', 'order_paid'), [
            'subject' => 'Thanks! Order {order_number} is paid',
            'body' => "Hello from {shop_name}.\n\n{items}\n\nGet your files: {order_url}\nQuestions? {support_email}",
        ])->assertRedirect(route('admin.email-templates.edit', 'order_paid'));
        $this->assertDatabaseHas('audit_log', ['action' => 'email_template.updated']);

        $mail = new OrderPaidMail($order->fresh(['items', 'buyer']));
        $this->assertSame('Thanks! Order '.$order->shortId().' is paid', $mail->envelope()->subject);
        $text = $mail->render();
        $this->assertStringContainsString('Hello from '.config('app.name').'.', $text);
        $this->assertStringContainsString('- Icon pack x 1: delivered', $text);
        $this->assertStringContainsString(route('orders.show', $order), $text);
        $this->assertStringContainsString('Questions? '.config('shop.support_email'), $text);
        $this->get(route('admin.email-templates.index'))->assertSee('Customised');

        $this->deleteForm(route('admin.email-templates.reset', 'order_paid'))->assertSessionHas('success');
        $this->assertSame('Order '.$order->shortId().' paid', (new OrderPaidMail($order))->envelope()->subject);
        $this->assertStringContainsString('Download links and licence keys:', (new OrderPaidMail($order))->render());
    }

    public function test_unknown_or_missing_placeholders_are_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->putForm(route('admin.email-templates.update', 'order_paid'), ['subject' => 'Paid {order_total}', 'body' => 'See {order_url}'])
            ->assertSessionHasErrors(['body' => '{order_total} is not a placeholder of this email. Use one of: {shop_name}, {support_email}, {order_number}, {total}, {items}, {order_url}.']);
        // Security emails must keep their confirmation link.
        $this->putForm(route('admin.email-templates.update', 'email_change_confirm'), ['subject' => 'Confirm', 'body' => 'Please confirm.'])
            ->assertSessionHasErrors(['body' => 'The text must contain {confirm_url}; without it the email is useless or unsafe.']);
        $this->assertNull(EmailTemplates::override('order_paid'));
        $this->get(route('admin.email-templates.edit', 'nope'))->assertNotFound();
    }

    public function test_preview_renders_sample_data_without_saving(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->putForm(route('admin.email-templates.update', 'dispute_resolved'), [
            'subject' => 'Dispute #{dispute_number}: {outcome}', 'body' => "Closed. Refund: {refund}\n{dispute_url}", 'preview' => '1',
        ])->assertOk()->assertSee('Preview (not saved)')->assertSee('Dispute #5: a replacement')->assertSee('Refund: 10.00 USD');
        $this->assertNull(EmailTemplates::override('dispute_resolved'));
    }

    public function test_only_super_admins_and_managers_edit_templates(): void
    {
        foreach ([UserRole::Moderator, UserRole::Finance, UserRole::Support] as $role) {
            $this->actingAs(User::factory()->staff($role)->create());
            $this->get(route('admin.email-templates.index'))->assertForbidden();
            $this->putForm(route('admin.email-templates.update', 'order_paid'), ['subject' => 'x', 'body' => '{order_url}'])->assertForbidden();
        }
    }
}
