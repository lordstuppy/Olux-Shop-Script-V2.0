<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Exceptions\UserFacingException;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Payment;
use App\Services\CartService;
use App\Services\DeliveryService;
use App\Services\DisputeService;
use App\Services\InvoiceService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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

    public function show(Order $order, DeliveryService $delivery, DisputeService $disputeService): View
    {
        Gate::authorize('view', $order);
        $order->load(['items.product.files', 'payments', 'invoice']);
        $disputes = Dispute::query()->where('order_id', $order->id)->get()->keyBy('order_item_id');
        $disputable = auth()->id() === $order->buyer_id
            ? $order->items->filter(fn ($item) => ! $disputes->has($item->id) && $disputeService->canOpen($item))->pluck('id')->all()
            : [];

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

        return view('orders.show', ['order' => $order, 'downloads' => $downloads, 'disputes' => $disputes, 'disputable' => $disputable]);
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
            'partial' => $payment !== null ? $this->partialSummary($payment) : null,
            'qr' => $qr,
            'cryptos' => $payments->paymentCryptos(),
            'balanceEnabled' => (bool) config('shop.payments_balance_enabled'),
            'user' => auth()->user(),
            'stale' => $payment !== null && $payments->quoteIsStale($payment),
        ]);
    }

    /**
     * What is still due after a partial payment, in fiat and (approximately,
     * pro rata to the current quote) in the invoice's crypto.
     *
     * @return array{received: int, due: int, crypto_due: ?string}|null
     */
    private function partialSummary(Payment $payment): ?array
    {
        if ($payment->status !== PaymentStatus::Partial || $payment->received_minor <= 0 || $payment->amount_minor <= 0) {
            return null;
        }
        $due = max(0, $payment->amount_minor - $payment->received_minor);
        $cryptoDue = null;
        if ($payment->crypto_amount !== null && is_numeric($payment->crypto_amount)) {
            $scale = max(8, strlen((string) strrchr($payment->crypto_amount, '.')) - 1);
            $cryptoDue = (string) BigDecimal::of($payment->crypto_amount)->multipliedBy($due)
                ->dividedBy($payment->amount_minor, $scale, RoundingMode::Up)->strippedOfTrailingZeros();
        }

        return ['received' => $payment->received_minor, 'due' => $due, 'crypto_due' => $cryptoDue];
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

    /**
     * "Buy again" for an expired or cancelled order: puts the items that can
     * still be bought back into the cart. Prices and stock are those of today.
     */
    public function reorder(Order $order, CartService $cart): RedirectResponse
    {
        Gate::authorize('act', $order);
        if (! in_array($order->status, [OrderStatus::Expired, OrderStatus::Cancelled], true)) {
            return redirect()->route('orders.show', $order);
        }

        $added = 0;
        $skipped = [];
        foreach ($order->items()->with('product')->get() as $item) {
            $product = $item->product;
            $quantity = $product?->stock === null ? $item->quantity : min($item->quantity, (int) $product->stock);
            try {
                if ($product === null || $quantity < 1) {
                    throw new UserFacingException(__('":title" is sold out.', ['title' => $item->title]));
                }
                $inCart = $cart->rawItems()[$product->id] ?? 0;
                if ($inCart === 0 && count($cart->rawItems()) >= (int) config('shop.max_cart_lines')) {
                    throw new UserFacingException(__('Your cart is full. Check out or remove an item before adding more.'));
                }
                $cart->update($product, max($inCart, $quantity));
                if (! $product->isPurchasable()) {
                    $cart->remove($product->id);
                    throw new UserFacingException(__('":title" is not available for purchase right now.', ['title' => $item->title]));
                }
                $added++;
            } catch (UserFacingException $e) {
                $skipped[] = $e->getMessage();
            }
        }

        $redirect = redirect()->route('cart.show');
        if ($added > 0) {
            $redirect->with('success', trans_choice('{1} Added :count item from order :order to your cart. Prices are today\'s prices.|[0,*] Added :count items from order :order to your cart. Prices are today\'s prices.', $added, ['order' => $order->shortId()]));
        }

        return $skipped === [] ? $redirect : $redirect->with('error', implode(' ', $skipped));
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
