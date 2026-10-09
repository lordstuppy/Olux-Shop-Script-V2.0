<?php

namespace App\Http\Controllers;

use App\Enums\TicketCategory;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TicketController extends Controller
{
    public function index(Request $request): View
    {
        return view('tickets.index', [
            'tickets' => $request->user()->tickets()->with('order')->latest('updated_at')->paginate(20),
        ]);
    }

    public function create(Request $request): View
    {
        $order = null;
        if ($request->filled('order')) {
            $order = Order::query()->where('public_id', (string) $request->query('order'))->first();
            if ($order !== null) {
                Gate::authorize('act', $order);
            }
        }

        return view('tickets.create', ['order' => $order]);
    }

    public function store(Request $request, TicketService $tickets): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'category' => ['required', Rule::enum(TicketCategory::class)],
            'order' => ['nullable', 'uuid'],
        ]);

        $order = null;
        if (! empty($data['order'])) {
            $order = Order::query()->where('public_id', $data['order'])->firstOrFail();
            Gate::authorize('act', $order);
        }

        $ticket = $tickets->open($request->user(), $data['subject'], $data['body'], TicketCategory::from($data['category']), $order);

        return redirect()->route('tickets.show', $ticket)->with('success', "Ticket #{$ticket->id} opened. We usually reply within one business day.");
    }

    public function show(Ticket $ticket): View
    {
        Gate::authorize('view', $ticket);
        $ticket->load(['messages.author', 'order', 'user']);

        return view('tickets.show', ['ticket' => $ticket]);
    }

    public function reply(Request $request, Ticket $ticket, TicketService $tickets): RedirectResponse
    {
        Gate::authorize('reply', $ticket);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $tickets->reply($ticket, $request->user(), $data['body']);

        return redirect()->route('tickets.show', $ticket)->with('success', "Reply added to ticket #{$ticket->id}.");
    }

    public function close(Request $request, Ticket $ticket, TicketService $tickets): RedirectResponse
    {
        Gate::authorize('view', $ticket);
        $tickets->close($ticket, $request->user());

        return redirect()->route('tickets.show', $ticket)->with('success', "Ticket #{$ticket->id} closed.");
    }
}
