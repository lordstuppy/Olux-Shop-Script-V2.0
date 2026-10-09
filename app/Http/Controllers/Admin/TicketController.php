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
        $data = $request->validate(['status' => ['nullable', Rule::enum(TicketStatus::class)]]);
        $query = Ticket::with(['user', 'order'])->latest('updated_at');
        $query->where('status', $data['status'] ?? TicketStatus::Open->value);

        return view('admin.tickets', ['tickets' => $query->paginate(30)->withQueryString(), 'filters' => $data]);
    }
}
