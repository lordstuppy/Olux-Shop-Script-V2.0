<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lets people end their other signed-in sessions. Session ids are never
 * shown; rows are addressed by an HMAC of the id.
 */
class SessionController extends Controller
{
    public static function handle(string $sessionId): string
    {
        return hash_hmac('sha256', $sessionId, (string) config('app.key'));
    }

    public function destroy(Request $request, string $handle, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $deleted = false;
        foreach (DB::table('sessions')->where('user_id', $user->id)->pluck('id') as $id) {
            if ($id !== $request->session()->getId() && hash_equals(self::handle($id), $handle)) {
                DB::table('sessions')->where('id', $id)->delete();
                $deleted = true;
            }
        }
        if (! $deleted) {
            throw new UserFacingException('That session no longer exists.');
        }
        $audit->log('user.session_revoked', $user, [], $user);

        return back()->with('success', 'The session was signed out.');
    }

    public function destroyOthers(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $count = DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        // Invalidate "remember me" cookies on other devices too.
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        $audit->log('user.sessions_revoked', $user, ['count' => $count], $user);

        return back()->with('success', "Signed out {$count} other ".($count === 1 ? 'session' : 'sessions').'.');
    }
}
