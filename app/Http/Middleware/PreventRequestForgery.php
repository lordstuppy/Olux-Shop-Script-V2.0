<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Base;

/**
 * Same as the framework middleware, but never switched off during tests, so
 * the test suite exercises real CSRF protection, and without the script-readable
 * XSRF-TOKEN cookie: every form carries the token, and the shop's own script
 * only sends GET requests.
 */
class PreventRequestForgery extends Base
{
    protected $addHttpCookie = false;

    protected function runningUnitTests()
    {
        return false;
    }
}
