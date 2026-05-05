<?php

namespace Tests\Feature;

use App\Models\WorkerTaskDispatchRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerTaskDispatchRegistryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_confirms_invoice_dispatch_registry_entries(): void
    {
        WorkerTaskDispatchRegistry::query()->create([
            'entity_type' => 'invoice',
            'entity_id' => 1333439,
            'document_id' => '002-FC-22848',
            'event_type' => 'migration',
            'source' => 'api',
            'task_id' => 'task-1',
            'accepted_at' => now(),
        ]);

        $response = $this->getJson('/api/internal/dispatch-registry/check?entity_type=invoice&document_id=002-FC-22848&event_type=migration');

        $response->assertOk()
            ->assertJsonPath('exists', true)
            ->assertJsonPath('entity_type', 'invoice')
            ->assertJsonPath('document_id', '002-FC-22848')
            ->assertJsonPath('event_type', 'migration')
            ->assertJsonPath('task_id', 'task-1');
    }

    public function test_it_returns_false_when_invoice_dispatch_registry_is_missing(): void
    {
        $response = $this->getJson('/api/internal/dispatch-registry/check?entity_type=invoice&document_id=002-FC-99999&event_type=migration');

        $response->assertOk()
            ->assertJsonPath('exists', false)
            ->assertJsonPath('entity_type', 'invoice')
            ->assertJsonPath('document_id', '002-FC-99999')
            ->assertJsonPath('event_type', 'migration')
            ->assertJsonMissing(['task_id', 'accepted_at']);
    }
}

