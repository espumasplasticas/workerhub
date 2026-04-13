<?php

namespace Tests\Feature;

use App\Contracts\BackofficeAuthClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WorkerHubHealthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_reports_degraded_status_when_backoffice_auth_is_unavailable(): void
    {
        $client = Mockery::mock(BackofficeAuthClientInterface::class);
        $client->shouldReceive('health')
            ->once()
            ->andReturn([
                'ok' => false,
                'status_code' => 503,
                'message' => 'Backoffice no disponible',
            ]);
        $this->app->instance(BackofficeAuthClientInterface::class, $client);

        $response = $this->getJson('/api/health/workerhub');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.backoffice.ok', false);
    }
}
