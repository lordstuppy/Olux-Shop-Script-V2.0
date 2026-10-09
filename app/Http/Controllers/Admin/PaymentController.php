<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['status' => ['nullable', Rule::enum(PaymentStatus::class)]]);
        $query = Payment::with('order')->latest('id');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return view('admin.payments', ['payments' => $query->paginate(40)->withQueryString(), 'filters' => $data]);
    }
}
