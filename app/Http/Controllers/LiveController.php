<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use App\Support\LivePages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Polled by enhance.js (about every 10 s while the tab is visible): unread
 * notifications, the wallet balance and the version of the page being shown.
 */
class LiveController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $path = $request->query('path');
        $unread = UserNotification::query()->where('user_id', $user->id)->whereNull('read_at');
        $latest = (clone $unread)->orderByDesc('id')->first(['id', 'title']);

        return response()->json([
            'unread' => $unread->count(),
            'latest' => $latest ? ['id' => $latest->id, 'title' => $latest->title, 'url' => route('notifications.open', $latest->id)] : null,
            'balance' => money($user->balance_minor, $user->currency),
            'version' => is_string($path) ? LivePages::version($user, mb_substr($path, 0, 500)) : null,
        ])->header('Cache-Control', 'no-store, private');
    }
}
