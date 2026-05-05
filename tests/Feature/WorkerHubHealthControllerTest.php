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
        config()->set('workerhub.backoffice.health_critical', true);
        config()->set('epsa_library.soap.url', 'https://siesa.test/ws');
        config()->set('epsa_library.soap.user', 'user');
        config()->set('epsa_library.soap.password', 'secret');
        config()->set('epsa_library.soap.connection', 'UNOEE');

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

    public function test_it_reports_ok_when_non_critical_backoffice_health_is_unavailable(): void
    {
        config()->set('workerhub.backoffice.health_critical', false);
        config()->set('epsa_library.soap.url', 'https://siesa.test/ws');
        config()->set('epsa_library.soap.user', 'user');
        config()->set('epsa_library.soap.password', 'secret');
        config()->set('epsa_library.soap.connection', 'UNOEE');

        $client = Mockery::mock(BackofficeAuthClientInterface::class);
        $client->shouldReceive('health')
            ->once()
            ->andReturn([
                'ok' => false,
                'status_code' => 405,
                'message' => null,
            ]);
        $this->app->instance(BackofficeAuthClientInterface::class, $client);

        $response = $this->getJson('/api/health/workerhub');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.backoffice.ok', false)
            ->assertJsonPath('checks.backoffice.critical', false);
    }

    public function test_it_reports_degraded_status_when_siesa_soap_configuration_is_missing(): void
    {
        config()->set('epsa_library.soap.url', '');
        config()->set('epsa_library.soap.user', '');
        config()->set('epsa_library.soap.password', '');
        config()->set('epsa_library.soap.connection', '');

        $client = Mockery::mock(BackofficeAuthClientInterface::class);
        $client->shouldReceive('health')
            ->once()
            ->andReturn([
                'ok' => true,
                'status_code' => 200,
                'message' => 'Backoffice disponible',
            ]);
        $this->app->instance(BackofficeAuthClientInterface::class, $client);

        $response = $this->getJson('/api/health/workerhub');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.siesa_import.ok', false)
            ->assertJsonPath('checks.siesa_import.missing.0', 'EPSA_SIESA_SOAP_URL');
    }
}
