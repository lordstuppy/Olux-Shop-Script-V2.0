<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WebhookEventStatus;
use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\AuditLogger;
use App\Services\WebhookProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WebhookEventController extends Controller
{
    public function index(): View
    {
        return view('admin.webhooks', ['events' => WebhookEvent::latest('id')->paginate(40)]);
    }

    public function retry(Request $request, WebhookEvent $event, WebhookProcessor $processor, AuditLogger $audit): RedirectResponse
    {
        abort_unless(in_array($event->status, [WebhookEventStatus::Failed, WebhookEventStatus::Dead], true), 422, 'Only failed events can be retried.');
        $audit->log('webhook.retried', $event);
        $processor->process($event);

        return back()->with('success', "Webhook event #{$event->id} reprocessed: {$event->status->value}.");
    }
}
