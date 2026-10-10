<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

class OutageTest extends TestCase
{
    public function test_a_lost_database_connection_gives_a_503_page_with_retry_after(): void
    {
        Route::middleware('web')->get('/__outage', fn () => throw new QueryException('pgsql', 'select 1', [],
            new PDOException('SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: Connection refused')));

        $this->get('/__outage')->assertStatus(503)->assertHeader('Retry-After', '30')
            ->assertSee('Temporarily unavailable')->assertDontSee('SQLSTATE');
        $this->getJson('/__outage')->assertStatus(503)->assertJson(['message' => 'Temporarily unavailable.']);
    }

    public function test_other_database_errors_stay_500(): void
    {
        Route::middleware('web')->get('/__broken', fn () => throw new QueryException('pgsql', 'select x', [], new PDOException('SQLSTATE[42703]: Undefined column')));

        $this->get('/__broken')->assertStatus(500);
    }

    public function test_health_and_metrics_do_not_start_a_session(): void
    {
        $this->get('/health')->assertOk()->assertCookieMissing(config('session.cookie'));
        $this->assertNotContains(StartSession::class, Route::getRoutes()->getByName('health')->gatherMiddleware());
    }
}
