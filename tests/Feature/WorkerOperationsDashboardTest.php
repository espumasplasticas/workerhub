<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerOperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_the_operations_dashboard(): void
    {
        config()->set('workerhub.operations.allow_local_bypass', true);

        $this->get('/')
            ->assertOk()
            ->assertSee('WorkerHub Monitor')
            ->assertSee('Centro operativo de colas y migracion.')
            ->assertSee('Bandeja operativa');

        $this->assertDatabaseHas('worker_operation_logs', [
            'action' => 'monitor.view',
            'status' => 'success',
            'channel' => 'local_bypass',
        ]);
    }
}
