<?php

namespace Tests\Feature;

use App\Contracts\BackofficeAuthClientInterface;
use App\Data\Auth\BackofficeAuthResult;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WorkerHubAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_logs_in_an_authorized_backoffice_operator(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);
        config()->set('workerhub.operations.allow_local_bypass', false);

        $client = Mockery::mock(BackofficeAuthClientInterface::class);
        $client->shouldReceive('authenticateOperator')
            ->once()
            ->with('admin@comodisimos.com', 'secret')
            ->andReturn(BackofficeAuthResult::fromPayload([
                'authenticated' => true,
                'authorized' => true,
                'active' => true,
                'role_id' => 20,
                'user' => [
                    'id' => 44,
                    'email' => 'admin@comodisimos.com',
                    'name' => 'Admin WorkerHub',
                ],
            ], 200));
        $this->app->instance(BackofficeAuthClientInterface::class, $client);

        $this->post('/login', [
            'username' => 'admin@comodisimos.com',
            'password' => 'secret',
        ])->assertRedirect(route('monitor.dashboard'));

        $this->assertSame('admin@comodisimos.com', session('workerhub.operator.email'));

        $this->get('/monitor')->assertOk();
        $this->get('/horizon')->assertOk();

        $this->assertDatabaseHas('worker_operation_logs', [
            'action' => 'auth.login.success',
            'status' => 'success',
            'actor' => 'admin@comodisimos.com',
            'channel' => 'web_session',
        ]);
    }

    public function test_it_rejects_user_without_backoffice_admin_role(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);
        config()->set('workerhub.operations.allow_local_bypass', false);

        $client = Mockery::mock(BackofficeAuthClientInterface::class);
        $client->shouldReceive('authenticateOperator')
            ->once()
            ->andReturn(BackofficeAuthResult::fromPayload([
                'authenticated' => true,
                'authorized' => false,
                'active' => true,
                'role_id' => null,
                'user' => [
                    'id' => 50,
                    'email' => 'viewer@comodisimos.com',
                    'name' => 'Viewer',
                ],
            ], 403));
        $this->app->instance(BackofficeAuthClientInterface::class, $client);

        $this->from('/login')
            ->post('/login', [
                'username' => 'viewer@comodisimos.com',
                'password' => 'secret',
            ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('username');

        $this->assertDatabaseHas('worker_operation_logs', [
            'action' => 'auth.login.denied',
            'status' => 'failed',
        ]);
    }

    public function test_it_logs_out_an_authenticated_operator(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);
        config()->set('workerhub.operations.allow_local_bypass', false);

        $response = $this->withSession([
            'workerhub.operator' => [
                'id' => 1,
                'email' => 'admin@comodisimos.com',
                'name' => 'Admin',
                'authorized' => true,
                'role_id' => 20,
                'authenticated_at' => now()->toIso8601String(),
                'access_channel' => 'web_session',
            ],
        ])->post('/logout');

        $response->assertRedirect(route('workerhub.login'));
        $response->assertSessionMissing('workerhub.operator');

        $this->assertDatabaseHas('worker_operation_logs', [
            'action' => 'auth.logout',
            'status' => 'success',
            'actor' => 'admin@comodisimos.com',
            'channel' => 'web_session',
        ]);
    }

    public function test_it_uses_development_bypass_when_backoffice_rejects_credentials_and_bypass_is_enabled(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);
        config()->set('workerhub.operations.allow_local_bypass', true);

        $client = Mockery::mock(BackofficeAuthClientInterface::class);
        $client->shouldReceive('authenticateOperator')
            ->once()
            ->with('lialvarez', 'secret')
            ->andReturn(BackofficeAuthResult::fromPayload([
                'authenticated' => false,
                'authorized' => false,
                'active' => true,
                'role_id' => null,
                'user' => null,
            ], 401));
        $this->app->instance(BackofficeAuthClientInterface::class, $client);

        $this->post('/login', [
            'username' => 'lialvarez',
            'password' => 'secret',
        ])->assertRedirect(route('monitor.dashboard'));

        $this->assertSame('lialvarez', session('workerhub.operator.email'));
        $this->assertSame('local_bypass', session('workerhub.operator.access_channel'));

        $this->assertDatabaseHas('worker_operation_logs', [
            'action' => 'auth.login.local_bypass',
            'status' => 'success',
            'actor' => 'lialvarez',
            'channel' => 'local_bypass',
        ]);
    }

    public function test_it_redirects_web_monitor_to_login_when_no_session_or_token_exists(): void
    {
        config()->set('workerhub.operations.allow_local_bypass', false);
        config()->set('workerhub.operations.access_token', '');

        $this->get('/monitor')
            ->assertRedirect(route('workerhub.login'));

        $this->assertDatabaseHas('worker_operation_logs', [
            'action' => 'auth.access.denied',
            'status' => 'failed',
        ]);
    }

    public function test_it_blocks_horizon_when_no_authorized_session_exists(): void
    {
        config()->set('workerhub.operations.allow_local_bypass', false);
        config()->set('workerhub.operations.access_token', '');

        $this->get('/horizon')->assertForbidden();
    }
}
