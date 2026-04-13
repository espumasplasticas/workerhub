<?php

namespace Tests\Feature;

use App\Services\Kafka\KafkaMessageProducer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WorkerTaskControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accepts_and_persists_a_worker_task_before_publishing_to_kafka(): void
    {
        $producer = Mockery::mock(KafkaMessageProducer::class);
        $producer->shouldReceive('publish')->once();
        $this->app->instance(KafkaMessageProducer::class, $producer);

        $response = $this->postJson('/api/worker-tasks', [
            'type' => 'document_migration',
            'priority' => 'default',
            'payload' => [
                'document_id' => 'DOC-1001',
                'source' => 'crm',
                'lines' => ['04300002001...', '04310002001...'],
            ],
        ]);

        $response->assertAccepted();

        $taskId = $response->json('task_id');

        $this->assertDatabaseHas('worker_tasks', [
            'id' => $taskId,
            'type' => 'document_migration',
            'status' => 'published',
            'source' => 'crm',
            'kafka_key' => 'DOC-1001',
        ]);

        $this->assertDatabaseHas('worker_task_events', [
            'worker_task_id' => $taskId,
            'event' => 'task.received',
        ]);

        $this->assertDatabaseHas('worker_task_events', [
            'worker_task_id' => $taskId,
            'event' => 'task.published',
        ]);
    }
}
