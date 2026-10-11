<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The notification centre: every event the user was emailed about, plus events that are only shown here. */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = UserNotification::query()->where('user_id', $request->user()->id)->orderByDesc('id')->paginate(20);

        return view('notifications.index', ['notifications' => $notifications]);
    }

    /** Marks the entry read and follows its link. */
    public function open(Request $request, int $notification): RedirectResponse
    {
        $entry = UserNotification::query()->where('user_id', $request->user()->id)->findOrFail($notification);
        if ($entry->read_at === null) {
            $entry->forceFill(['read_at' => now()])->save();
        }

        // Stored links are paths inside the shop (Notifier::localPath).
        return redirect($entry->url !== null && str_starts_with($entry->url, '/') && ! str_starts_with($entry->url, '//')
            ? $entry->url : route('notifications.index'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $count = UserNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('success', trans_choice('{0} Nothing was unread.|{1} :count notification marked as read.|[2,*] :count notifications marked as read.', $count));
    }
}
