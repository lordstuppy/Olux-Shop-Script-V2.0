<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'actor' => ['nullable', 'integer'],
            'target_type' => ['nullable', 'string', 'max:64'],
            'target_id' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = AuditLog::with('actor')->latest('id');
        if (! empty($data['action'])) {
            $query->where('action', 'LIKE', addcslashes($data['action'], '%_\\').'%');
        }
        foreach (['target_type', 'target_id'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (! empty($data['actor'])) {
            $query->where('actor_id', $data['actor']);
        }
        if (! empty($data['from'])) {
            $query->where('created_at', '>=', $data['from']);
        }
        if (! empty($data['to'])) {
            $query->where('created_at', '<', \Illuminate\Support\Carbon::parse($data['to'])->addDay());
        }

        return view('admin.audit', ['entries' => $query->paginate(50)->withQueryString(), 'filters' => $data]);
    }
}
