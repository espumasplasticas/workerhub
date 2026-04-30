<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerHubOperatorAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_blocks_monitor_endpoints_when_operator_token_is_required(): void
    {
        config()->set('workerhub.operations.access_token', 'secret-token');
        config()->set('workerhub.operations.allow_local_bypass', false);

        $this->getJson('/api/monitor/tasks/summary')
            ->assertForbidden();
    }

    public function test_it_allows_monitor_endpoints_with_valid_operator_token(): void
    {
        config()->set('workerhub.operations.access_token', 'secret-token');
        config()->set('workerhub.operations.allow_local_bypass', false);

        $this->getJson('/api/monitor/tasks/summary', [
            'X-WorkerHub-Token' => 'secret-token',
        ])->assertOk();
    }
}
