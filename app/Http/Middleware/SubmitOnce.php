<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forms that create money or value (staff balance adjustments, gift cards)
 * carry a one-time key (components/once). A second request with the same key,
 * from a double click or a resent form, is not processed again. It gets the
 * first request's success message instead, because the browser shows only the
 * last response: otherwise a gift card's one-time code would never be seen.
 * That message is kept encrypted, for this user, for ten minutes.
 *
 * Only a successful request uses up the key, so a form that failed (a
 * validation error, a refused amount) can be sent again.
 */
class SubmitOnce
{
    private const KEY_TTL_HOURS = 24;

    private const RESULT_TTL_MINUTES = 10;

    public function handle(Request $request, Closure $next): Response
    {
        $once = $request->input('_once');
        if (! is_string($once) || ! Str::isUuid($once)) {
            return back()->with('error', __('This form is out of date. Reload the page and send it again.'));
        }
        $key = 'submit-once:'.$request->user()->id.':'.$once;

        if (! Cache::add($key, 'pending', now()->addHours(self::KEY_TTL_HOURS))) {
            $result = Cache::get($key.':result');

            return is_string($result)
                ? back()->with('success', Crypt::decryptString($result))
                : back()->with('info', __('This form was already sent and is being processed. It was not sent a second time.'));
        }

        $response = $next($request);

        $success = $request->session()->get('success');
        if (is_string($success)) {
            Cache::put($key.':result', Crypt::encryptString($success), now()->addMinutes(self::RESULT_TTL_MINUTES));
        } else {
            Cache::forget($key);
        }

        return $response;
    }
}
