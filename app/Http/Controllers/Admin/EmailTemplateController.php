<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\EmailTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EmailTemplateController extends Controller
{
    public function index(): View
    {
        $overrides = DB::table('email_templates')->get(['key', 'updated_at', 'updated_by'])->keyBy('key');

        return view('admin.email-templates.index', ['definitions' => EmailTemplates::definitions(), 'overrides' => $overrides]);
    }

    public function edit(string $key): View
    {
        $definition = $this->definition($key);
        $current = EmailTemplates::override($key);

        return view('admin.email-templates.edit', [
            'key' => $key,
            'definition' => $definition,
            'customised' => $current !== null,
            'template' => $current ?? EmailTemplates::starter($key),
            'preview' => null,
        ]);
    }

    /** Saves, or with the preview button shows the text with sample values without saving. */
    public function update(Request $request, string $key, AuditLogger $audit): View|RedirectResponse
    {
        $definition = $this->definition($key);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
        ]);
        $subject = trim($data['subject']);
        $body = str_replace("\r\n", "\n", $data['body']);
        if ($problems = EmailTemplates::problems($key, $subject, $body)) {
            throw ValidationException::withMessages(['body' => $problems]);
        }

        if ($request->has('preview')) {
            $sample = EmailTemplates::sample($key);

            return view('admin.email-templates.edit', [
                'key' => $key,
                'definition' => $definition,
                'customised' => EmailTemplates::override($key) !== null,
                'template' => ['subject' => $subject, 'body' => $body],
                'preview' => ['subject' => EmailTemplates::render($subject, $sample), 'body' => EmailTemplates::render($body, $sample)],
            ]);
        }

        EmailTemplates::save($key, $subject, $body, $request->user());
        $audit->log('email_template.updated', null, ['key' => $key]);

        return redirect()->route('admin.email-templates.edit', $key)->with('success', __('Saved. ":label" emails now use this text.', ['label' => $definition['label']]));
    }

    public function reset(string $key, AuditLogger $audit): RedirectResponse
    {
        $definition = $this->definition($key);
        EmailTemplates::reset($key);
        $audit->log('email_template.reset', null, ['key' => $key]);

        return redirect()->route('admin.email-templates.edit', $key)->with('success', __('":label" emails use the built-in text again.', ['label' => $definition['label']]));
    }

    private function definition(string $key): array
    {
        return EmailTemplates::definitions()[$key] ?? abort(404);
    }
}
