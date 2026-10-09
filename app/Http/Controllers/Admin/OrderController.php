<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\AuditLogger;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Services\ShkeeperPayoutService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'q' => ['nullable', 'string', 'max:64'],
        ]);
        $query = Order::with('buyer')->latest('id');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['q'])) {
            $query->whereRaw('CAST(public_id AS TEXT) LIKE ?', [addcslashes(strtolower($data['q']), '%_\\').'%']);
        }

        return view('admin.orders.index', ['orders' => $query->paginate(30)->withQueryString(), 'filters' => $data]);
    }

    public function show(Order $order): View
    {
        $order->load(['buyer', 'items.seller', 'payments.parent', 'coupon', 'invoice']);

        return view('admin.orders.show', [
            'order' => $order,
            'shkeeperEnabled' => ShkeeperPayoutService::enabled(),
            'cryptos' => ShkeeperPayoutService::enabled() ? app(PaymentService::class)->availableCryptos() : [],
        ]);
    }

    public function cancel(Request $request, Order $order, OrderService $orders): RedirectResponse
    {
        $orders->cancel($order, $request->user());

        return back()->with('success', __('Order :order cancelled; reserved stock and coupon were released.', ['order' => $order->shortId()]));
    }

    public function deliverItem(Request $request, Order $order, OrderItem $item, DeliveryService $delivery): RedirectResponse
    {
        abort_unless($item->order_id === $order->id, 404);
        $data = $request->validate(['payload' => ['required', 'string', 'max:10000']]);
        $delivery->deliverManually($item, $request->user(), $data['payload'], asStaff: true);

        return back()->with('success', __('Delivered ":title" on behalf of the seller; the buyer was emailed.', ['title' => $item->title]));
    }

    public function retryDelivery(Order $order): RedirectResponse
    {
        abort_unless($order->status->isPaidState(), 422, __('Only paid orders can be delivered.'));
        DeliverOrder::dispatch($order->id);

        return back()->with('success', __('Delivery of order :order queued again; delivered items are skipped and the buyer gets the delivery email.', ['order' => $order->shortId()]));
    }

    public function regenerateInvoice(Order $order, InvoiceService $invoices, AuditLogger $audit): RedirectResponse
    {
        abort_unless($order->status->isPaidState(), 422, __('Invoices exist only for paid orders.'));
        $invoice = $invoices->regenerate($order);
        $audit->log('invoice.regenerated', $order, ['number' => $invoice->number]);

        return back()->with('success', __('Invoice :number regenerated.', ['number' => $invoice->number]));
    }

    public function resetDownloads(Order $order, OrderItem $item, AuditLogger $audit): RedirectResponse
    {
        abort_unless($item->order_id === $order->id, 404);
        $before = $item->download_count;
        $item->forceFill(['download_count' => 0])->save();
        $audit->log('order_item.downloads_reset', $item, ['before' => $before, 'order' => $order->public_id]);

        return back()->with('success', __('Download counter for ":title" reset (was :before).', ['title' => $item->title, 'before' => $before]));
    }

    public function refund(Request $request, Order $order, RefundService $refunds, ShkeeperPayoutService $shkeeper): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:16'],
            'method' => ['required', Rule::in(['balance', 'manual', 'shkeeper'])],
            'reference' => ['nullable', 'string', 'max:128'],
            'crypto' => ['nullable', 'required_if:method,shkeeper', 'string', 'max:32'],
            'destination' => ['nullable', 'required_if:method,shkeeper', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $amount = Money::parseInput($data['amount'], $order->currency);
        } catch (InvalidArgumentException) {
            throw new UserFacingException(__('Enter the refund amount as a number such as 5.00 in :currency.', ['currency' => $order->currency]));
        }

        if ($data['method'] === 'shkeeper') {
            $payment = $shkeeper->sendRefund($order, $amount, $data['crypto'], $data['destination'], $request->user(), $data['reason'] ?? null);

            return back()->with('success', __('Refund of :amount sent to Shkeeper as :crypto_amount :crypto. It is booked when Shkeeper confirms the transfer.', ['amount' => Money::format($amount, $order->currency), 'crypto_amount' => $payment->crypto_amount, 'crypto' => $payment->crypto]));
        }

        $refunds->refund($order, $amount, $data['method'], $request->user(), $data['reference'] ?? null, $data['reason'] ?? null);

        return back()->with('success', __('Refunded :amount on order :order via :method.', ['amount' => Money::format($amount, $order->currency), 'order' => $order->shortId(), 'method' => $data['method']]));
    }
}
