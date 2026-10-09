<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Base;

/**
 * Same as the framework middleware, but never switched off during tests, so
 * the test suite exercises real CSRF protection.
 */
class PreventRequestForgery extends Base
{
    protected function runningUnitTests()
    {
        return false;
    }
}
