<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(): View
    {
        return view('admin.settings', [
            'fields' => collect(Settings::EDITABLE)->map(fn ($def, $key) => [
                'label' => $def[0],
                'hint' => $def[2],
                'value' => config('shop.'.$key),
            ]),
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $rules = collect(Settings::EDITABLE)->map(fn ($def) => array_merge(['required'], $def[1]))->all();
        $data = $request->validate($rules);

        $changed = collect($data)->filter(fn ($value, $key) => (string) config('shop.'.$key) !== (string) $value)->all();
        Settings::save($data, $request->user());
        if ($changed !== []) {
            $audit->log('settings.updated', null, $changed);
        }

        return back()->with('success', $changed === [] ? 'No settings changed.' : 'Saved '.count($changed).' changed '.(count($changed) === 1 ? 'setting' : 'settings').'.');
    }
}
