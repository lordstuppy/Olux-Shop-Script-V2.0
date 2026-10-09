<?php

namespace App\Http\Controllers;

use App\Enums\DisputeReason;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\DisputeService;
use App\Services\PaymentService;
use App\Services\ShkeeperPayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Buyer, seller and staff side of a dispute; staff resolve it in Admin\DisputeController. */
class DisputeController extends Controller
{
    public function __construct(private readonly DisputeService $disputes) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('disputes.index', [
            'asBuyer' => Dispute::query()->with(['item', 'order'])->where('buyer_id', $user->id)->latest('id')->limit(100)->get(),
            'asSeller' => $user->isSeller() ? Dispute::query()->with(['item', 'order'])->where('seller_id', $user->id)->latest('id')->limit(100)->get() : collect(),
        ]);
    }

    public function create(Order $order, OrderItem $item): View|RedirectResponse
    {
        Gate::authorize('act', $order);
        abort_unless($item->order_id === $order->id, 404);
        if ($existing = Dispute::query()->where('order_item_id', $item->id)->first()) {
            return redirect()->route('disputes.show', $existing);
        }
        if (! $this->disputes->canOpen($item)) {
            $until = $this->disputes->windowEndsAt($item);

            return redirect()->route('orders.show', $order)->with('error', $until !== null && $until->isPast()
                ? __('The dispute window for ":title" closed on :date. Contact support if you still need help.', ['title' => $item->title, 'date' => $until->format('Y-m-d')])
                : __('":title" cannot be disputed. Contact support if you need help.', ['title' => $item->title]));
        }

        return view('disputes.create', ['order' => $order, 'item' => $item, 'until' => $this->disputes->windowEndsAt($item)]);
    }

    public function store(Request $request, Order $order, OrderItem $item): RedirectResponse
    {
        Gate::authorize('act', $order);
        abort_unless($item->order_id === $order->id, 404);
        $data = $request->validate([
            'reason' => ['required', Rule::enum(DisputeReason::class)],
            'requested_outcome' => ['required', Rule::in(['refund', 'replacement'])],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
        ], ['body.min' => __('Describe the problem in at least 10 characters so the seller can help.')]);

        $dispute = $this->disputes->open($item, $request->user(), DisputeReason::from($data['reason']), $data['requested_outcome'], $data['body']);

        return redirect()->route('disputes.show', $dispute)->with('success', __('Dispute #:id opened. The seller has until :time UTC to respond; we will email you about every update.', [
            'id' => $dispute->id, 'time' => $dispute->seller_respond_by->format('Y-m-d H:i'),
        ]));
    }

    public function show(Request $request, Dispute $dispute, PaymentService $payments): View
    {
        Gate::authorize('view', $dispute);
        $user = $request->user();
        $staff = $user->can('disputes.manage') && $user->id !== $dispute->buyer_id && $user->id !== $dispute->seller_id;
        $dispute->load(['item.product', 'order', 'buyer', 'seller.sellerProfile', 'resolver']);
        $item = $dispute->item;

        return view('disputes.show', [
            'dispute' => $dispute,
            'item' => $item,
            'staff' => $staff,
            'role' => $this->disputes->roleOf($dispute, $user),
            'messages' => ($staff ? $dispute->messages() : $dispute->publicMessages())->with('author')->get(),
            'refundable' => max(0, $item->netMinor() - $item->refunded_minor),
            'cryptos' => $staff && ShkeeperPayoutService::enabled() ? $payments->availableCryptos() : [],
        ]);
    }

    public function reply(Request $request, Dispute $dispute): RedirectResponse
    {
        Gate::authorize('reply', $dispute);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000'], 'internal' => ['nullable', 'boolean']]);
        $this->disputes->reply($dispute, $request->user(), $data['body'], (bool) ($data['internal'] ?? false));

        return back()->with('success', __('Message added to dispute #:id.', ['id' => $dispute->id]));
    }

    public function withdraw(Request $request, Dispute $dispute): RedirectResponse
    {
        Gate::authorize('reply', $dispute);
        $this->disputes->withdraw($dispute, $request->user());

        return back()->with('success', __('Dispute #:id withdrawn.', ['id' => $dispute->id]));
    }
}
