<?php

namespace App\Http\Controllers\Admin;

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
        // Claimed under the same lock as the retry job, so the two never run it together.
        $claimed = $processor->claim($event->id, manual: true);
        abort_if($claimed === null, 422, __('Only failed events, or events stuck for more than :minutes minutes, can be retried.', ['minutes' => (int) config('shop.webhook_stale_minutes')]));
        $audit->log('webhook.retried', $claimed);
        $processor->process($claimed);

        return back()->with('success', __('Webhook event #:id reprocessed: :status.', ['id' => $claimed->id, 'status' => mb_strtolower($claimed->status->label())]));
    }
}
