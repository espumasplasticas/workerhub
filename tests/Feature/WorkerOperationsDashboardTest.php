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
            ->assertSee('Monitor operativo de colas, replay y DLQ');

        $this->assertDatabaseHas('worker_operation_logs', [
            'action' => 'monitor.view',
            'status' => 'success',
            'channel' => 'web',
        ]);
    }
}
