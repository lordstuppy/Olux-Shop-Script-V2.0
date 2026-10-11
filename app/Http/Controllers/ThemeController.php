<?php

namespace App\Http\Controllers;

use App\Support\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Stores the colour scheme chosen in the footer (a cookie, so it works for guests and without JavaScript). */
class ThemeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['theme' => ['required', Rule::in(Theme::CHOICES)]]);

        return back()->withCookie(Theme::cookie($data['theme']));
    }
}
