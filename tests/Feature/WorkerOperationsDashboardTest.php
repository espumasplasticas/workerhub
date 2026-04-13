<?php

namespace Tests\Feature;

use Tests\TestCase;

class WorkerOperationsDashboardTest extends TestCase
{
    public function test_it_renders_the_operations_dashboard(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Monitor operativo de colas, replay y DLQ');
    }
}
