<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\WebhookEventStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /** Liveness and readiness: database reachable, queue backlog visible. */
    public function health(): JsonResponse
    {
        $checks = [];
        $healthy = true;

        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $checks['database'] = ['status' => 'ok', 'latency_ms' => round((microtime(true) - $start) * 1000, 1)];
        } catch (Throwable $e) {
            $healthy = false;
            $checks['database'] = ['status' => 'fail'];
        }

        if ($healthy) {
            $checks['queue'] = [
                'status' => 'ok',
                'pending_jobs' => DB::table('jobs')->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
            ];
        }

        return response()->json(['status' => $healthy ? 'ok' : 'fail', 'checks' => $checks], $healthy ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    /** Prometheus text exposition, protected by a bearer token. */
    public function metrics(Request $request): Response
    {
        $token = (string) config('shop.metrics_token');
        abort_if($token === '', 404);
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401);

        $lines = [];
        $gauge = function (string $name, string $help, iterable $rows) use (&$lines) {
            $lines[] = "# HELP {$name} {$help}";
            $lines[] = "# TYPE {$name} gauge";
            foreach ($rows as $labels => $value) {
                $lines[] = $labels === '' ? "{$name} {$value}" : "{$name}{{$labels}} {$value}";
            }
        };

        $orders = DB::table('orders')->groupBy('status')->selectRaw('status, COUNT(*) AS c')->pluck('c', 'status');
        $gauge('shop_orders', 'Orders by status.', collect(OrderStatus::cases())->mapWithKeys(fn ($s) => ["status=\"{$s->value}\"" => (int) ($orders[$s->value] ?? 0)]));

        $payments = DB::table('payments')->groupBy('provider', 'status')->selectRaw('provider, status, COUNT(*) AS c')->get();
        $gauge('shop_payments', 'Payments by provider and status.', $payments->mapWithKeys(fn ($r) => ["provider=\"{$r->provider}\",status=\"{$r->status}\"" => (int) $r->c]));

        $events = DB::table('webhook_events')->groupBy('status')->selectRaw('status, COUNT(*) AS c')->pluck('c', 'status');
        $gauge('shop_webhook_events', 'Stored webhook events by status.', collect(WebhookEventStatus::cases())->mapWithKeys(fn ($s) => ["status=\"{$s->value}\"" => (int) ($events[$s->value] ?? 0)]));

        $revenue = DB::table('orders')->whereIn('status', ['paid', 'delivered', 'partially_refunded', 'refunded'])
            ->groupBy('currency')->selectRaw('currency, SUM(total_minor - refunded_minor) AS s')->pluck('s', 'currency');
        $gauge('shop_net_revenue_minor', 'Net captured revenue in minor units.', $revenue->mapWithKeys(fn ($v, $c) => ["currency=\"{$c}\"" => (int) $v]));

        $gauge('shop_queue_pending_jobs', 'Jobs waiting in the database queue.', ['' => DB::table('jobs')->count()]);
        $gauge('shop_queue_failed_jobs', 'Jobs that exhausted their retries.', ['' => DB::table('failed_jobs')->count()]);
        $gauge('shop_users', 'Registered users.', ['' => DB::table('users')->count()]);

        $lines[] = '# HELP shop_exceptions_total Unexpected exceptions reported since the cache was last cleared.';
        $lines[] = '# TYPE shop_exceptions_total counter';
        $lines[] = 'shop_exceptions_total '.(int) Cache::get('metrics:exceptions_total', 0);

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4']);
    }
}
