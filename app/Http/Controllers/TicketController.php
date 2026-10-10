<?php

namespace App\Http\Controllers;

use App\Enums\TicketCategory;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
        if (is_string($request->query('order')) && Str::isUuid($request->query('order'))) {
            $order = Order::query()->where('public_id', $request->query('order'))->first();
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

        return redirect()->route('tickets.show', $ticket)->with('success', __('Ticket #:id opened. We usually reply within one business day.', ['id' => $ticket->id]));
    }

    public function show(Ticket $ticket): View
    {
        Gate::authorize('view', $ticket);
        $staff = auth()->user()->can('tickets.manage');
        $ticket->load(['order', 'user', 'assignee']);
        $messages = ($staff ? $ticket->messages() : $ticket->publicMessages())->with('author')->get();

        return view('tickets.show', [
            'ticket' => $ticket,
            'messages' => $messages,
            'staff' => $staff,
            'assignees' => $staff ? User::query()->whereIn('role', Permissions::rolesFor('tickets.manage'))->orderBy('email')->get(['id', 'email']) : collect(),
        ]);
    }

    public function reply(Request $request, Ticket $ticket, TicketService $tickets): RedirectResponse
    {
        Gate::authorize('reply', $ticket);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000'], 'internal' => ['nullable', 'boolean']]);
        $internal = $request->boolean('internal');
        $tickets->reply($ticket, $request->user(), $data['body'], $internal);

        return redirect()->route('tickets.show', $ticket)->with('success', $internal ? __('Internal note added to ticket #:id.', ['id' => $ticket->id]) : __('Reply added to ticket #:id.', ['id' => $ticket->id]));
    }

    public function assign(Request $request, Ticket $ticket, TicketService $tickets): RedirectResponse
    {
        abort_unless($request->user()->can('tickets.manage'), 403);
        $data = $request->validate(['assigned_to' => ['nullable', 'integer', 'exists:users,id']]);
        $assignee = isset($data['assigned_to']) ? User::find($data['assigned_to']) : null;
        $tickets->assign($ticket, $assignee, $request->user());

        return back()->with('success', $assignee ? __('Ticket #:id assigned to :email.', ['id' => $ticket->id, 'email' => $assignee->email]) : __('Ticket #:id unassigned.', ['id' => $ticket->id]));
    }

    public function close(Request $request, Ticket $ticket, TicketService $tickets): RedirectResponse
    {
        Gate::authorize('view', $ticket);
        $tickets->close($ticket, $request->user());

        return redirect()->route('tickets.show', $ticket)->with('success', __('Ticket #:id closed.', ['id' => $ticket->id]));
    }
}
