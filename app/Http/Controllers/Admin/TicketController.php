<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TicketController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'assigned' => ['nullable', Rule::in(['me', 'none', 'any'])],
        ]);
        $query = Ticket::with(['user', 'order', 'assignee'])->latest('updated_at');
        $query->where('status', $data['status'] ?? TicketStatus::Open->value);
        match ($data['assigned'] ?? 'any') {
            'me' => $query->where('assigned_to', $request->user()->id),
            'none' => $query->whereNull('assigned_to'),
            default => null,
        };

        return view('admin.tickets', ['tickets' => $query->paginate(30)->withQueryString(), 'filters' => $data]);
    }
}
