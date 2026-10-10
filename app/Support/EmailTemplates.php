<?php

namespace App\Support;

use App\Mail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Registry of editable emails. Staff edit plain text with {placeholders};
 * only the placeholders listed for an email are allowed, and emails that
 * carry a confirmation link must keep it. Overrides are cached and apply to
 * every recipient of that email (for dispute emails: buyer, seller and
 * staff alike).
 */
final class EmailTemplates
{
    private const CACHE_KEY = 'email_templates.overrides';

    /** Placeholders available in every email. */
    public const GLOBALS = [
        'shop_name' => 'Shop name',
        'support_email' => 'Support email address',
    ];

    /**
     * @return array<string, array{class: class-string, label: string, audience: string, placeholders: array<string, string>, required: list<string>}>
     */
    public static function definitions(): array
    {
        return [
            'order_placed' => ['class' => Mail\OrderPlacedMail::class, 'label' => __('Order confirmation (awaiting payment)'), 'audience' => __('Buyer'),
                'placeholders' => ['order_number' => __('Short order number'), 'total' => __('Order total with currency'), 'crypto' => __('Chosen cryptocurrency'), 'pay_url' => __('Payment page link'), 'expires_at' => __('When the unpaid order expires (UTC)')],
                'required' => ['pay_url']],
            'order_paid' => ['class' => Mail\OrderPaidMail::class, 'label' => __('Order paid'), 'audience' => __('Buyer'),
                'placeholders' => ['order_number' => __('Short order number'), 'total' => __('Order total with currency'), 'items' => __('One line per item with its delivery state'), 'order_url' => __('Order page link (downloads and keys)')],
                'required' => ['order_url']],
            'order_delivered' => ['class' => Mail\OrderDeliveredMail::class, 'label' => __('Item delivered'), 'audience' => __('Buyer'),
                'placeholders' => ['order_number' => __('Short order number'), 'order_url' => __('Order page link')], 'required' => ['order_url']],
            'refund_issued' => ['class' => Mail\RefundIssuedMail::class, 'label' => __('Refund issued'), 'audience' => __('Buyer'),
                'placeholders' => ['order_number' => __('Short order number'), 'amount' => __('Refund amount with currency'), 'method' => __('How the refund was paid'), 'order_url' => __('Order page link')], 'required' => []],
            'payout_status' => ['class' => Mail\PayoutStatusMail::class, 'label' => __('Payout status changed'), 'audience' => __('Seller'),
                'placeholders' => ['payout_number' => __('Payout number'), 'amount' => __('Payout amount with currency'), 'status' => __('New status'), 'destination' => __('Payout address'), 'reference' => __('Transaction reference, if paid'), 'note' => __('Staff note, if rejected'), 'payouts_url' => __('Payout history link')], 'required' => []],
            'seller_sale' => ['class' => Mail\SellerSaleMail::class, 'label' => __('New sale'), 'audience' => __('Seller'),
                'placeholders' => ['order_number' => __('Short order number'), 'items' => __('One line per sold item'), 'sales_url' => __('Sales overview link'), 'dashboard_url' => __('Seller dashboard link (manual delivery)')], 'required' => []],
            'subscription_renewal' => ['class' => Mail\SubscriptionRenewalMail::class, 'label' => __('Subscription ending soon'), 'audience' => __('Buyer'),
                'placeholders' => ['product' => __('Product title'), 'order_number' => __('Short order number'), 'ends_at' => __('End of access (UTC)'), 'renew_url' => __('Product page link')], 'required' => []],
            'new_device_login' => ['class' => Mail\NewDeviceLoginMail::class, 'label' => __('Sign-in from a new device'), 'audience' => __('Any user'),
                'placeholders' => ['email' => __('Account email'), 'time' => __('Sign-in time (UTC)'), 'ip' => __('IP address'), 'browser' => __('Browser'), 'account_url' => __('Account settings link')], 'required' => ['account_url']],
            'login_locked' => ['class' => Mail\LoginLockedMail::class, 'label' => __('Sign-in paused after failed attempts'), 'audience' => __('Any user'),
                'placeholders' => ['email' => __('Account email'), 'attempts' => __('Number of failed attempts'), 'minutes' => __('Minutes until sign-in works again'), 'reset_url' => __('Password reset link')], 'required' => ['reset_url']],
            'email_change_confirm' => ['class' => Mail\EmailChangeConfirmMail::class, 'label' => __('Confirm new email address'), 'audience' => __('Any user (new address)'),
                'placeholders' => ['current_email' => __('Current account email'), 'confirm_url' => __('Confirmation link')], 'required' => ['confirm_url']],
            'email_change_notice' => ['class' => Mail\EmailChangeNoticeMail::class, 'label' => __('Email change requested (old address)'), 'audience' => __('Any user'),
                'placeholders' => ['new_email' => __('Requested new address'), 'account_url' => __('Account settings link')], 'required' => ['account_url']],
            'payout_address_change' => ['class' => Mail\PayoutAddressChangeMail::class, 'label' => __('Confirm new payout address'), 'audience' => __('Seller'),
                'placeholders' => ['shop' => __('Shop name of the seller'), 'new_address' => __('New payout address'), 'crypto' => __('Payout cryptocurrency'), 'confirm_url' => __('Confirmation link'), 'account_url' => __('Account settings link')],
                'required' => ['confirm_url', 'new_address']],
            'payout_address_changed' => ['class' => Mail\PayoutAddressChangedMail::class, 'label' => __('Payout address changed'), 'audience' => __('Seller'),
                'placeholders' => ['shop' => __('Shop name of the seller'), 'address' => __('New payout address'), 'crypto' => __('Payout cryptocurrency'), 'pause_hours' => __('Payout pause in hours')], 'required' => ['address']],
            'seller_application' => ['class' => Mail\SellerApplicationMail::class, 'label' => __('Seller application decision'), 'audience' => __('Applicant'),
                'placeholders' => ['shop' => __('Requested shop name'), 'decision' => __('approved or not approved'), 'reason' => __('Staff note when not approved'), 'next_url' => __('Next step link')], 'required' => []],
            'ticket_reply' => ['class' => Mail\TicketReplyMail::class, 'label' => __('Support ticket reply'), 'audience' => __('Customer'),
                'placeholders' => ['ticket_number' => __('Ticket number'), 'subject' => __('Ticket subject'), 'ticket_url' => __('Ticket link')], 'required' => ['ticket_url']],
            'dispute_opened' => ['class' => Mail\DisputeOpenedMail::class, 'label' => __('Dispute opened'), 'audience' => __('Seller and support team'),
                'placeholders' => ['dispute_number' => __('Dispute number'), 'order_number' => __('Short order number'), 'product' => __('Item title'), 'reason' => __('Reason given by the buyer'), 'outcome' => __('What the buyer asks for'), 'respond_by' => __('Seller deadline (UTC)'), 'dispute_url' => __('Dispute link')],
                'required' => ['dispute_url']],
            'dispute_message' => ['class' => Mail\DisputeMessageMail::class, 'label' => __('New dispute message'), 'audience' => __('Buyer, seller and support team'),
                'placeholders' => ['dispute_number' => __('Dispute number'), 'product' => __('Item title'), 'from' => __('Who wrote: buyer, seller or support team'), 'dispute_url' => __('Dispute link')], 'required' => ['dispute_url']],
            'dispute_escalated' => ['class' => Mail\DisputeEscalatedMail::class, 'label' => __('Dispute escalated to staff'), 'audience' => __('Buyer, seller and support team'),
                'placeholders' => ['dispute_number' => __('Dispute number'), 'product' => __('Item title'), 'dispute_url' => __('Dispute link')], 'required' => ['dispute_url']],
            'dispute_resolved' => ['class' => Mail\DisputeResolvedMail::class, 'label' => __('Dispute resolved'), 'audience' => __('Buyer and seller'),
                'placeholders' => ['dispute_number' => __('Dispute number'), 'product' => __('Item title'), 'outcome' => __('Outcome'), 'refund' => __('Refund amount, if any'), 'note' => __('Staff note'), 'dispute_url' => __('Dispute link')], 'required' => ['dispute_url']],
        ];
    }

    /**
     * Starting text for the editor: the built-in email written with
     * placeholders. (The built-in email itself is the translated view.)
     *
     * @return array{subject: string, body: string}
     */
    public static function starter(string $key): array
    {
        $pair = match ($key) {
            'order_placed' => ['Order {order_number} placed: awaiting payment', "Order {order_number} was placed and is waiting for payment.\n\nTotal: {total}\nPay with: {crypto}\n\nPayment instructions (sign in required): {pay_url}\nUnpaid orders expire at {expires_at} UTC.\n\nOnly send funds to the address shown on the payment page. We never send payment addresses in emails."],
            'order_paid' => ['Order {order_number} paid', "Order {order_number} paid.\n\nTotal: {total}\n\n{items}\n\nDownload links and licence keys: {order_url}\n(Sign in to open the link.)"],
            'order_delivered' => ['Order {order_number}: an item was delivered', "An item in order {order_number} was delivered.\n\nView the delivery details: {order_url}"],
            'refund_issued' => ['Refund for order {order_number}', "A refund of {amount} was issued for order {order_number}: {method}.\n\nOrder details: {order_url}"],
            'payout_status' => ['Payout #{payout_number} is {status}', "Payout #{payout_number} of {amount} is now {status}.\n\nDestination: {destination}\nReference: {reference}\n{note}\n\nPayout history: {payouts_url}"],
            'seller_sale' => ['New sale: order {order_number}', "You sold items in order {order_number}:\n\n{items}\n\nSales overview: {sales_url}\nDeliver manual items from your dashboard: {dashboard_url}"],
            'subscription_renewal' => ['Your access to "{product}" ends on {ends_at}', "Your access to \"{product}\" (order {order_number}) ends on {ends_at} UTC.\n\nTo keep access, renew it here: {renew_url}"],
            'new_device_login' => ['New sign-in to your {shop_name} account', "Your account {email} was signed in from a new device.\n\nTime: {time} UTC\nIP address: {ip}\nBrowser: {browser}\n\nIf this was you, no action is needed.\nIf it was not you, change your password now and review signed-in sessions: {account_url}"],
            'email_change_confirm' => ['Confirm your new email address', "Someone asked to use this address for the {shop_name} account currently registered as {current_email}.\n\nTo confirm, sign in and open this link within 24 hours:\n{confirm_url}\n\nIf you did not ask for this, ignore this email; nothing changes."],
            'login_locked' => ['Sign-in to your account was paused', "There were {attempts} failed sign-in attempts on your account {email}, so sign-in is paused for {minutes} minutes.\n\nIf this was you, wait and try again, or reset your password to sign in right away: {reset_url}\nIf it was not you, your password was not accepted. Resetting it and turning on two-factor authentication keeps the account safe."],
            'email_change_notice' => ['Email change requested for your account', "A change of your account email to {new_email} was requested. It takes effect only after the new address confirms it.\n\nIf this was not you, change your password now and sign out other sessions: {account_url}"],
            'payout_address_change' => ['Confirm your new payout address', "A change of the payout address for {shop} was requested.\n\nNew address: {new_address} ({crypto})\n\nTo confirm, sign in and open this link within 24 hours:\n{confirm_url}\n\nIf you did not request this, do not open the link. Change your password and turn on two-factor authentication: {account_url}"],
            'payout_address_changed' => ['Your payout address was changed', "The payout address for {shop} is now {address} ({crypto}).\n\nFor your security, payouts are paused for {pause_hours} hours after the change.\nIf you did not make this change, contact {support_email} immediately."],
            'seller_application' => ['Your seller application was {decision}', "Your application to sell as \"{shop}\" was {decision}.\n\n{reason}\n\nNext step: {next_url}"],
            'ticket_reply' => ['New reply on ticket #{ticket_number}: {subject}', "Our support team replied to ticket #{ticket_number}: {subject}\n\nRead and reply: {ticket_url}"],
            'dispute_opened' => ['Dispute #{dispute_number} opened on order {order_number}', "Dispute #{dispute_number} was opened about \"{product}\" (order {order_number}).\n\nReason: {reason}\nThe buyer asks for: {outcome}\n\nThe seller can respond until {respond_by} UTC. After that our team decides.\n\nOpen the dispute: {dispute_url}"],
            'dispute_message' => ['New message on dispute #{dispute_number}', "There is a new message from the {from} on dispute #{dispute_number} about \"{product}\".\n\nRead and reply: {dispute_url}"],
            'dispute_escalated' => ['Dispute #{dispute_number} is now with our team', "The seller did not respond to dispute #{dispute_number} about \"{product}\" in time, so our team will now review it.\n\nOpen the dispute: {dispute_url}"],
            'dispute_resolved' => ['Dispute #{dispute_number} closed: {outcome}', "Dispute #{dispute_number} about \"{product}\" is closed.\n\nOutcome: {outcome}\nRefund: {refund}\n\n{note}\n\nDetails: {dispute_url}"],
        };

        return ['subject' => $pair[0], 'body' => $pair[1]];
    }

    /** @return array{subject: string, body: string}|null */
    public static function override(string $key): ?array
    {
        try {
            $all = Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('email_templates')->get(['key', 'subject', 'body'])
                ->mapWithKeys(fn ($r) => [$r->key => ['subject' => $r->subject, 'body' => $r->body]])->all());
        } catch (Throwable) {
            return null;
        }

        return $all[$key] ?? null;
    }

    /** @return array<string, string> */
    public static function globals(): array
    {
        return ['shop_name' => (string) config('app.name'), 'support_email' => (string) config('shop.support_email')];
    }

    /** @param array<string, string> $data */
    public static function render(string $text, array $data): string
    {
        $pairs = [];
        foreach ($data as $name => $value) {
            $pairs['{'.$name.'}'] = (string) $value;
        }

        return strtr($text, $pairs);
    }

    /**
     * Problems with an edited template, as user-facing messages.
     *
     * @return list<string>
     */
    public static function problems(string $key, string $subject, string $body): array
    {
        $definition = self::definitions()[$key];
        $allowed = array_merge(array_keys(self::GLOBALS), array_keys($definition['placeholders']));
        $problems = [];
        preg_match_all('/\{([^{}\s]*)\}/', $subject.' '.$body, $matches);
        foreach (array_unique($matches[1]) as $name) {
            if (! in_array($name, $allowed, true)) {
                $problems[] = __('{:name} is not a placeholder of this email. Use one of: :list.', ['name' => $name, 'list' => '{'.implode('}, {', $allowed).'}']);
            }
        }
        foreach ($definition['required'] as $name) {
            if (! str_contains($body, '{'.$name.'}')) {
                $problems[] = __('The text must contain {:name}; without it the email is useless or unsafe.', ['name' => $name]);
            }
        }
        if (preg_match('/[\r\n]/', $subject)) {
            $problems[] = __('The subject must be a single line.');
        }

        return $problems;
    }

    /** @return array<string, string> sample values for the preview */
    public static function sample(string $key): array
    {
        $base = rtrim((string) config('app.url'), '/');
        $values = [
            'order_number' => 'a1b2c3d4', 'total' => '25.00 USD', 'crypto' => 'BTC', 'pay_url' => $base.'/orders/sample/pay', 'expires_at' => '2026-10-11 12:00',
            'items' => "- Markdown to PDF converter x 1: delivered\n- Icon pack x 2: waiting for delivery", 'order_url' => $base.'/orders/sample',
            'amount' => '10.00 USD', 'method' => 'credited to your shop balance', 'payout_number' => '42', 'status' => 'paid',
            'destination' => 'bc1qexampleaddress', 'reference' => 'tx-1234', 'note' => 'Thanks for your patience.', 'payouts_url' => $base.'/seller/payouts',
            'sales_url' => $base.'/seller/sales', 'dashboard_url' => $base.'/seller', 'product' => 'Icon pack', 'ends_at' => '2026-11-01 00:00',
            'renew_url' => $base.'/products/icon-pack', 'email' => 'buyer@example.com', 'time' => '2026-10-11 09:30', 'ip' => '203.0.113.7',
            'browser' => 'Firefox on Linux', 'account_url' => $base.'/account', 'current_email' => 'old@example.com', 'confirm_url' => $base.'/confirm/sample-token',
            'new_email' => 'new@example.com', 'shop' => 'Demo Studio', 'new_address' => 'bc1qnewaddress', 'address' => 'bc1qnewaddress', 'pause_hours' => '48',
            'decision' => 'approved', 'reason' => '', 'next_url' => $base.'/seller/products/new', 'ticket_number' => '17', 'subject' => 'Download question',
            'ticket_url' => $base.'/tickets/17', 'dispute_number' => '5', 'outcome' => 'a replacement', 'respond_by' => '2026-10-14 09:30',
            'dispute_url' => $base.'/disputes/5', 'from' => 'seller', 'refund' => '10.00 USD',
        ];
        $out = self::globals();
        foreach (array_keys(self::definitions()[$key]['placeholders']) as $name) {
            $out[$name] = $values[$name] ?? '';
        }

        return $out;
    }

    public static function save(string $key, string $subject, string $body, User $actor): void
    {
        DB::table('email_templates')->updateOrInsert(['key' => $key], [
            'subject' => $subject, 'body' => $body, 'updated_by' => $actor->id, 'updated_at' => now(), 'created_at' => now(),
        ]);
        Cache::forget(self::CACHE_KEY);
    }

    public static function reset(string $key): void
    {
        DB::table('email_templates')->where('key', $key)->delete();
        Cache::forget(self::CACHE_KEY);
    }
}
