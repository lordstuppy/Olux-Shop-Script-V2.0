<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\QueueHeartbeat;
use App\Models\User;
use App\Services\SystemHealthService;
use App\Support\GatewayLog;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    public function test_page_reports_stopped_background_processes(): void
    {
        Cache::forget(SystemHealthService::QUEUE_HEARTBEAT);
        Cache::forget(SystemHealthService::SCHEDULER_HEARTBEAT);
        $this->actingAs(User::factory()->staff(UserRole::Manager)->create());

        $this->get(route('admin.health'))->assertOk()
            ->assertSee('System health')->assertSee('Problem')
            ->assertSee('Queue worker:')->assertSee('no job seen yet')
            ->assertSee('Scheduler:')->assertSee('never ran')
            ->assertSee('Connection:')->assertSee('Pending migrations:</strong> 0', false)
            ->assertSee('Disk for uploaded files:')->assertSee('free of')
            ->assertSee('Last successful Shkeeper webhook:</strong> never', false)
            // phpunit.xml requires scanning but configures no clamd.
            ->assertSee('Virus scanner:</strong> not answering', false)->assertSee('CLAMAV_HOST is not configured');
    }

    public function test_heartbeats_and_last_webhook_turn_checks_green(): void
    {
        QueueHeartbeat::dispatchSync();
        $this->assertNotNull(Cache::get(SystemHealthService::QUEUE_HEARTBEAT));
        $this->artisan('schedule:run')->assertSuccessful();
        $this->assertNotNull(Cache::get(SystemHealthService::SCHEDULER_HEARTBEAT));
        GatewayLog::record('webhook', 'ok', ['external_id' => 'x']);

        $this->actingAs(User::factory()->admin()->create());
        $page = $this->get(route('admin.health'))->assertOk();
        $page->assertSee('last job')->assertSee('last run')->assertDontSee('Last successful Shkeeper webhook:</strong> never', false);

        $checks = app(SystemHealthService::class)->checks();
        $background = collect($checks['Background work'])->keyBy('label');
        $this->assertSame('good', $background['Queue worker']['level']);
        $this->assertSame('good', $background['Scheduler']['level']);

        $this->travel(15)->minutes();
        $background = collect(app(SystemHealthService::class)->checks()['Background work'])->keyBy('label');
        $this->assertSame('critical', $background['Queue worker']['level']);
        $this->assertSame('critical', $background['Scheduler']['level']);
    }

    public function test_access(): void
    {
        $this->actingAs(User::factory()->staff(UserRole::Finance)->create());
        $this->get(route('admin.health'))->assertOk();
        foreach ([UserRole::Moderator, UserRole::Support] as $role) {
            $this->actingAs(User::factory()->staff($role)->create());
            $this->get(route('admin.health'))->assertForbidden();
        }
    }
}
