<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerOperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_the_operations_dashboard(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('WorkerHub Monitor')
            ->assertSee('Monitor azul para operación de colas, DLQ y replay.');

        $this->assertDatabaseHas('worker_operation_logs', [
            'action' => 'monitor.view',
            'status' => 'success',
            'channel' => 'local_bypass',
        ]);
    }
}
