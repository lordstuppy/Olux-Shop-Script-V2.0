<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\SystemHealthService;
use App\Support\ErrorSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SystemHealthController extends Controller
{
    public function __invoke(SystemHealthService $health): View
    {
        $checks = $health->checks();
        $failedJobs = DB::table('failed_jobs')->orderByDesc('id')->limit(50)->get(['uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(fn ($job) => [
                'uuid' => $job->uuid,
                'name' => class_basename((string) (json_decode($job->payload, true)['displayName'] ?? __('unknown job'))),
                'queue' => $job->queue,
                'error' => Str::limit(ErrorSummary::fromLine(strtok((string) $job->exception, "\n") ?: ''), 300),
                'failed_at' => $job->failed_at,
            ]);

        return view('admin.health', [
            'checks' => $checks,
            'overall' => $health->overall($checks),
            'checkedAt' => now(),
            'failedJobs' => $failedJobs,
            'failedJobCount' => DB::table('failed_jobs')->count(),
        ]);
    }

    /** Puts one failed job (or all of them) back on the queue, e.g. emails after a mail server outage. */
    public function retry(Request $request, AuditLogger $audit, ?string $uuid = null): RedirectResponse
    {
        $ids = $uuid === null ? ['all'] : [$this->existing($uuid)];
        Artisan::call('queue:retry', ['id' => $ids]);
        $audit->log('jobs.retried', null, ['job' => $uuid ?? 'all'], $request->user());

        return back()->with('success', $uuid === null ? __('All failed jobs were queued again.') : __('The job was queued again.'));
    }

    public function forget(Request $request, AuditLogger $audit, string $uuid): RedirectResponse
    {
        Artisan::call('queue:forget', ['id' => $this->existing($uuid)]);
        $audit->log('jobs.deleted', null, ['job' => $uuid], $request->user());

        return back()->with('success', __('The failed job was deleted.'));
    }

    private function existing(string $uuid): string
    {
        if (! Str::isUuid($uuid) || ! DB::table('failed_jobs')->where('uuid', $uuid)->exists()) {
            throw new UserFacingException(__('That failed job no longer exists. It may have been retried or deleted already.'));
        }

        return $uuid;
    }
}
