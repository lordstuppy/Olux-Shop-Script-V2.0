<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DisputeStatus;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Services\DisputeService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DisputeController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['open', ...array_map(fn ($s) => $s->value, DisputeStatus::cases())])]]);
        $status = $data['status'] ?? 'open';
        $query = Dispute::query()->with(['item', 'order', 'buyer', 'seller.sellerProfile'])->orderBy('seller_respond_by');
        $status === 'open' ? $query->whereIn('status', DisputeStatus::openValues()) : $query->where('status', $status);

        return view('admin.disputes', [
            'disputes' => $query->paginate(50)->withQueryString(),
            'status' => $status,
            'counts' => Dispute::query()->groupBy('status')->selectRaw('status, COUNT(*) AS c')->pluck('c', 'status'),
        ]);
    }

    public function resolve(Request $request, Dispute $dispute, DisputeService $disputes): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['refund', 'replacement', 'reject'])],
            'note' => ['nullable', 'string', 'max:5000'],
            'amount' => ['required_if:action,refund', 'nullable', 'string', 'max:16'],
            'method' => ['required_if:action,refund', 'nullable', Rule::in(['balance', 'manual', 'shkeeper'])],
            'reference' => ['nullable', 'string', 'max:128'],
            'crypto' => ['nullable', 'string', 'max:32'],
            'destination' => ['nullable', 'string', 'max:255'],
            'replacement_text' => ['nullable', 'string', 'max:10000'],
        ]);
        $note = trim((string) ($data['note'] ?? ''));

        match ($data['action']) {
            'refund' => $disputes->resolveWithRefund($dispute, $request->user(), Money::parseInput((string) $data['amount'], $dispute->order->currency), [
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'crypto' => $data['crypto'] ?? null,
                'destination' => $data['destination'] ?? null,
            ], $note),
            'replacement' => $disputes->resolveWithReplacement($dispute, $request->user(), $data['replacement_text'] ?? null, $note),
            'reject' => $disputes->reject($dispute, $request->user(), $note),
        };

        return redirect()->route('disputes.show', $dispute)->with('success', __('Dispute #:id resolved: :resolution.', ['id' => $dispute->id, 'resolution' => mb_strtolower($dispute->fresh()->resolution->label())]));
    }
}
