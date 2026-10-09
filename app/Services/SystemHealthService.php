<?php

namespace App\Services;

use App\Services\Security\VirusScanner;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Checks for the admin system health page. Every check has a level (good,
 * warning, critical) that the page shows with a text label.
 */
class SystemHealthService
{
    public const QUEUE_HEARTBEAT = 'health:queue:last_processed';

    public const SCHEDULER_HEARTBEAT = 'health:scheduler:last_run';

    public function __construct(private readonly VirusScanner $scanner) {}

    /** @return array<string, list<array{label: string, value: string, level: string, hint: string}>> grouped by section */
    public function checks(): array
    {
        return [
            __('Database') => $this->database(),
            __('Background work') => $this->background(),
            __('Storage') => $this->storage(),
            __('Payments and scanning') => $this->integrations(),
            __('Application') => $this->application(),
        ];
    }

    /** Worst level across all checks. */
    public function overall(array $checks): string
    {
        $levels = collect($checks)->flatten(1)->pluck('level');

        return $levels->contains('critical') ? 'critical' : ($levels->contains('warning') ? 'warning' : 'good');
    }

    private function database(): array
    {
        try {
            $start = hrtime(true);
            $version = (string) DB::selectOne('SHOW server_version')->server_version;
            $latency = round((hrtime(true) - $start) / 1_000_000, 1);
            $size = (int) DB::selectOne('SELECT pg_database_size(current_database()) AS s')->s;
        } catch (Throwable $e) {
            return [$this->check(__('Connection'), __('failed'), 'critical', $e->getMessage())];
        }
        $pending = $this->pendingMigrations();

        return [
            $this->check(__('Connection'), __('connected, :ms ms', ['ms' => $latency]), $latency > 200 ? 'warning' : 'good', __('PostgreSQL :version', ['version' => $version])),
            $this->check(__('Database size'), $this->bytes($size), 'good', ''),
            $this->check(__('Pending migrations'), (string) $pending, $pending === 0 ? 'good' : 'critical', $pending > 0 ? __('Run php artisan migrate --force.') : ''),
        ];
    }

    private function background(): array
    {
        $lastJob = Cache::get(self::QUEUE_HEARTBEAT);
        $lastRun = Cache::get(self::SCHEDULER_HEARTBEAT);
        $pending = DB::table('jobs')->count();
        $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
        $oldestAge = $oldest ? now()->timestamp - (int) $oldest : 0;
        $failed = DB::table('failed_jobs')->count();

        return [
            $this->check(__('Queue worker'), $lastJob ? __('last job :ago', ['ago' => Carbon::createFromTimestamp($lastJob)->diffForHumans()]) : __('no job seen yet'),
                $lastJob && now()->timestamp - (int) $lastJob < 600 ? 'good' : 'critical',
                __('A heartbeat job is queued every five minutes. If nothing ran for ten minutes, check that php artisan queue:work is running.')),
            $this->check(__('Scheduler'), $lastRun ? __('last run :ago', ['ago' => Carbon::createFromTimestamp($lastRun)->diffForHumans()]) : __('never ran'),
                $lastRun && now()->timestamp - (int) $lastRun < 180 ? 'good' : 'critical',
                __('Runs every minute (php artisan schedule:work or cron). Without it orders do not expire and payments are not reconciled.')),
            $this->check(__('Jobs waiting'), __(':count, oldest :age', ['count' => $pending, 'age' => $oldest ? $this->duration($oldestAge) : '-']),
                $oldestAge < 300 ? 'good' : ($oldestAge < 1800 ? 'warning' : 'critical'), ''),
            $this->check(__('Failed jobs'), (string) $failed, $failed === 0 ? 'good' : 'warning', $failed > 0 ? __('Inspect with php artisan queue:failed; retry with php artisan queue:retry.') : ''),
        ];
    }

    private function storage(): array
    {
        $root = Storage::disk('products')->path('');
        @mkdir($root, 0770, true);
        $free = @disk_free_space($root);
        $total = @disk_total_space($root);
        $checks = [];
        if ($free === false || $total === false || $total <= 0) {
            $checks[] = $this->check(__('Disk for uploaded files'), __('unknown'), 'warning', __('The storage directory is not readable.'));
        } else {
            $share = $free / $total;
            $checks[] = $this->check(__('Disk for uploaded files'), __(':free free of :total (:pct%)', ['free' => $this->bytes((int) $free), 'total' => $this->bytes((int) $total), 'pct' => round($share * 100)]),
                $share < 0.02 || $free < 512 * 1024 * 1024 ? 'critical' : ($share < 0.10 || $free < 2 * 1024 * 1024 * 1024 ? 'warning' : 'good'), $root);
        }
        $files = DB::table('product_files')->whereNull('retired_at')->selectRaw('COUNT(*) AS n, COALESCE(SUM(size), 0) AS bytes')->first();
        $checks[] = $this->check(__('Product files'), __(':count files, :size', ['count' => $files->n, 'size' => $this->bytes((int) $files->bytes)]), 'good', '');
        $checks[] = $this->check(__('Writable'), is_writable($root) ? __('yes') : __('no'), is_writable($root) ? 'good' : 'critical', is_writable($root) ? '' : __('Uploads and invoices will fail.'));

        return $checks;
    }

    private function integrations(): array
    {
        $lastWebhook = DB::table('gateway_logs')->where('channel', 'webhook')->where('outcome', 'ok')->max('created_at')
            ?? DB::table('webhook_events')->where('provider', 'shkeeper')->where('status', 'processed')->max('processed_at');
        $lastApi = DB::table('gateway_logs')->where('channel', 'api')->where('outcome', 'ok')->max('created_at');
        $checks = [
            $this->check(__('Last successful Shkeeper webhook'), $lastWebhook ? Carbon::parse($lastWebhook)->format('Y-m-d H:i:s').' UTC ('.Carbon::parse($lastWebhook)->diffForHumans().')' : __('never'),
                $lastWebhook ? 'good' : 'warning', $lastWebhook ? '' : __('No payment callback was accepted yet. Fine for a new shop; otherwise check the callback URL.')),
            $this->check(__('Last successful Shkeeper API call'), $lastApi ? Carbon::parse($lastApi)->diffForHumans() : __('never'), $lastApi ? 'good' : 'warning', ''),
        ];
        $mode = (string) config('shop.virus_scan');
        if ($mode === 'disabled') {
            $checks[] = $this->check(__('Virus scanner'), __('disabled'), app()->isProduction() ? 'critical' : 'warning', __('Uploads are not scanned. Use SHOP_VIRUS_SCAN=required in production.'));
        } else {
            $ping = $this->scanner->ping();
            $checks[] = $this->check(__('Virus scanner'), $ping['ok'] ? __('answering') : __('not answering'), $ping['ok'] ? 'good' : 'critical', $ping['ok'] ? '' : $ping['detail']);
        }

        return $checks;
    }

    private function application(): array
    {
        $production = app()->isProduction();

        return [
            $this->check(__('Environment'), (string) app()->environment(), 'good', __('PHP :php, Laravel :laravel', ['php' => PHP_VERSION, 'laravel' => app()->version()])),
            $this->check(__('Debug mode'), config('app.debug') ? __('on') : __('off'), config('app.debug') && $production ? 'critical' : 'good', config('app.debug') && $production ? __('Set APP_DEBUG=false; debug pages leak secrets.') : ''),
            $this->check(__('HTTPS'), str_starts_with((string) config('app.url'), 'https://') ? __('yes') : __('no'), str_starts_with((string) config('app.url'), 'https://') || ! $production ? 'good' : 'critical', (string) config('app.url')),
            $this->check(__('Configuration cache'), app()->configurationIsCached() ? __('cached') : __('not cached'), app()->configurationIsCached() || ! $production ? 'good' : 'warning', ''),
        ];
    }

    private function pendingMigrations(): int
    {
        try {
            /** @var Migrator $migrator */
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles([database_path('migrations')]);
            $ran = $migrator->getRepository()->getRan();

            return count(array_diff(array_keys($files), $ran));
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array{label: string, value: string, level: string, hint: string} */
    private function check(string $label, string $value, string $level, string $hint): array
    {
        return compact('label', 'value', 'level', 'hint');
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) $bytes : number_format($value, 1)).' '.$units[$i];
    }

    private function duration(int $seconds): string
    {
        return $seconds < 120 ? __(':s s', ['s' => $seconds]) : ($seconds < 7200 ? __(':m min', ['m' => intdiv($seconds, 60)]) : __(':h h', ['h' => intdiv($seconds, 3600)]));
    }
}
