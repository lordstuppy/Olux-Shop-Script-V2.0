<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Models\Order;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Support\Money;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $orders = $request->user()->orders()->withCount('items')->latest('id')->paginate(15);

        return view('orders.index', ['orders' => $orders]);
    }

    public function show(Order $order, DeliveryService $delivery): View
    {
        Gate::authorize('view', $order);
        $order->load(['items.product.files', 'payments', 'invoice']);

        $downloads = [];
        if (auth()->id() === $order->buyer_id && in_array($order->status, [OrderStatus::Paid, OrderStatus::Delivered, OrderStatus::PartiallyRefunded], true)) {
            foreach ($order->items as $item) {
                if ($item->accessExpired() || $item->download_count >= $item->product->downloadLimit()) {
                    continue;
                }
                foreach ($item->delivered_payload['files'] ?? [] as $fileRef) {
                    $file = $item->product->files->firstWhere('id', $fileRef['id']);
                    if ($file !== null) {
                        $downloads[$item->id][] = ['name' => $file->original_name, 'size' => $file->size, 'url' => $delivery->downloadUrl($order, $item, $file)];
                    }
                }
            }
        }

        return view('orders.show', ['order' => $order, 'downloads' => $downloads]);
    }

    /** Server-rendered payment page: address, amount, QR code. No JavaScript needed. */
    public function pay(Order $order, PaymentService $payments): View|RedirectResponse
    {
        Gate::authorize('act', $order);
        if ($order->status !== OrderStatus::Pending) {
            return redirect()->route('orders.result', $order);
        }

        $payment = $order->charges()->where('provider', PaymentProvider::Shkeeper->value)->first();
        $qr = null;
        if ($payment?->wallet_address) {
            $options = new QROptions(['outputBase64' => true, 'svgAddXmlHeader' => false, 'eccLevel' => 0b00]);
            $qr = (new QRCode($options))->render($payment->wallet_address);
        }

        return view('orders.pay', [
            'order' => $order,
            'payment' => $payment,
            'qr' => $qr,
            'cryptos' => $payments->availableCryptos(),
            'user' => auth()->user(),
            'stale' => $payment !== null && $payments->quoteIsStale($payment),
        ]);
    }

    public function startPayment(Request $request, Order $order, PaymentService $payments): RedirectResponse
    {
        Gate::authorize('act', $order);
        $data = $request->validate(['crypto' => ['required', 'string', 'max:32']]);
        $payments->startShkeeperPayment($order, $data['crypto']);

        return redirect()->route('orders.pay', $order)->with('success', __('Invoice created. Send the exact :crypto amount shown below.', ['crypto' => $data['crypto']]));
    }

    public function payWithBalance(Order $order, PaymentService $payments): RedirectResponse
    {
        Gate::authorize('act', $order);
        $payments->payWithBalance($order, auth()->user());

        return redirect()->route('orders.result', $order);
    }

    public function cancel(Order $order, OrderService $orders): RedirectResponse
    {
        Gate::authorize('act', $order);
        $orders->cancel($order, auth()->user());

        return redirect()->route('orders.show', $order)->with('success', __('Order :order cancelled. Reserved stock was released.', ['order' => $order->shortId()]));
    }

    /**
     * Shows the state recorded by the server. Arriving here never changes the
     * order; only a verified notification does.
     */
    public function result(Order $order): View
    {
        Gate::authorize('view', $order);
        $order->load(['items', 'charges']);
        $charge = $order->charges->sortByDesc('id')->first();

        return view('orders.result', [
            'order' => $order,
            'charge' => $charge,
            'message' => $this->resultMessage($order, $charge),
        ]);
    }

    public function invoice(Order $order, InvoiceService $invoices): StreamedResponse
    {
        Gate::authorize('view', $order);
        abort_unless($order->status->isPaidState(), 404, __('Invoices are issued once an order is paid.'));

        $invoice = $invoices->issue($order);

        return Storage::disk('invoices')->download($invoice->storage_path, $invoice->number.'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function resultMessage(Order $order, $charge): array
    {
        $short = $order->shortId();

        return match (true) {
            $order->status === OrderStatus::Delivered => ['success', __('Order :order paid. Download link sent to your email.', ['order' => $short])],
            $order->status === OrderStatus::Paid => ['success', __('Order :order paid. Delivery is in progress; you will receive an email when it is ready.', ['order' => $short])],
            $order->status === OrderStatus::Pending && $charge?->failure_reason !== null => ['error', $charge->failure_reason],
            $order->status === OrderStatus::Pending && $charge?->received_minor > 0 => ['info', __(
                'Partial payment received for order :order: :received of :total. Send the remaining amount to the same address.',
                ['order' => $short, 'received' => Money::format($charge->received_minor, $order->currency), 'total' => Money::format($order->total_minor, $order->currency)],
            )],
            $order->status === OrderStatus::Pending => ['info', __('Order :order is waiting for payment confirmation. This page refreshes every 30 seconds.', ['order' => $short])],
            default => ['info', __('Order :order is :status.', ['order' => $short, 'status' => mb_strtolower($order->status->label())])],
        };
    }
}
