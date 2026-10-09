<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Does nothing. The scheduler queues it every five minutes so the system
 * health page can tell an idle queue from a stopped worker: every processed
 * job (this one included) records a heartbeat in AppServiceProvider.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function handle(): void {}
}
