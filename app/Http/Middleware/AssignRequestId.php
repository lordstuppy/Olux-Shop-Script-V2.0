<?php

namespace App\Http\Middleware;

use App\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id that appears in logs, audit entries, the
 * X-Request-Id response header and the generic error page.
 */
class AssignRequestId
{
    public function __construct(private readonly RequestId $requestId) {}

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        // Accept an id from the reverse proxy only if it looks harmless.
        $id = preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming) ? $incoming : bin2hex(random_bytes(8));

        $this->requestId->set($id);
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
