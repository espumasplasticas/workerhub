<?php

namespace Tests\Feature;

use App\Models\WorkerTask;
use App\Services\Kafka\KafkaMessageProducer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WorkerTaskMonitorControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_monitor_summary(): void
    {
        WorkerTask::query()->create([
            'id' => 'task-1',
            'type' => 'document_migration',
            'source' => 'crm',
            'status' => 'completed',
            'priority' => 'default',
        ]);

        WorkerTask::query()->create([
            'id' => 'task-2',
            'type' => 'document_migration',
            'source' => 'erp',
            'status' => 'failed',
            'priority' => 'high',
        ]);

        $response = $this->getJson('/api/monitor/tasks/summary');

        $response->assertOk()
            ->assertJson([
                'total' => 2,
                'completed' => 1,
                'failed' => 1,
            ]);
    }

    public function test_it_returns_socket_configuration_for_monitor_clients(): void
    {
        config()->set('workerhub.broadcasting.channel', 'workerhub.monitor');
        config()->set('workerhub.broadcasting.task_channel_prefix', 'workerhub.tasks');
        config()->set('broadcasting.default', 'pusher');

        $response = $this->getJson('/api/monitor/socket-config');

        $response->assertOk()
            ->assertJson([
                'broadcaster' => 'pusher',
                'channels' => [
                    'monitor' => 'workerhub.monitor',
                    'task_prefix' => 'workerhub.tasks',
                ],
            ]);
    }

    public function test_it_returns_only_dead_letters(): void
    {
        WorkerTask::query()->create([
            'id' => 'task-ok',
            'type' => 'document_migration',
            'status' => 'completed',
            'priority' => 'default',
        ]);

        WorkerTask::query()->create([
            'id' => 'task-failed',
            'type' => 'document_migration',
            'status' => 'failed',
            'priority' => 'default',
        ]);

        WorkerTask::query()->create([
            'id' => 'task-rejected',
            'type' => 'document_migration',
            'status' => 'rejected',
            'priority' => 'default',
        ]);

        $response = $this->getJson('/api/monitor/dead-letters');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_it_replays_a_failed_task_and_creates_a_child_task(): void
    {
        $producer = Mockery::mock(KafkaMessageProducer::class);
        $producer->shouldReceive('publish')->once();
        $this->app->instance(KafkaMessageProducer::class, $producer);

        WorkerTask::query()->create([
            'id' => 'task-dead-letter',
            'type' => 'document_migration',
            'source' => 'crm',
            'status' => 'failed',
            'priority' => 'high',
            'payload' => [
                'document_id' => 'DOC-900',
                'source' => 'crm',
            ],
            'metadata' => [
                'headers' => ['tenant' => 'comodisimos'],
            ],
        ]);

        $response = $this->postJson('/api/monitor/tasks/task-dead-letter/retry');

        $response->assertAccepted()
            ->assertJson([
                'accepted' => true,
                'replayed_from_task_id' => 'task-dead-letter',
            ]);

        $newTaskId = $response->json('task_id');

        $this->assertDatabaseHas('worker_tasks', [
            'id' => $newTaskId,
            'parent_task_id' => 'task-dead-letter',
            'status' => 'published',
            'priority' => 'high',
        ]);

        $this->assertDatabaseHas('worker_task_events', [
            'worker_task_id' => 'task-dead-letter',
            'event' => 'task.replayed',
        ]);
    }

    public function test_it_replays_multiple_failed_tasks_in_batch(): void
    {
        $producer = Mockery::mock(KafkaMessageProducer::class);
        $producer->shouldReceive('publish')->twice();
        $this->app->instance(KafkaMessageProducer::class, $producer);

        WorkerTask::query()->create([
            'id' => 'task-batch-1',
            'type' => 'document_migration',
            'status' => 'failed',
            'priority' => 'default',
            'payload' => ['document_id' => 'DOC-1', 'source' => 'crm'],
        ]);

        WorkerTask::query()->create([
            'id' => 'task-batch-2',
            'type' => 'document_migration',
            'status' => 'rejected',
            'priority' => 'high',
            'payload' => ['document_id' => 'DOC-2', 'source' => 'erp'],
        ]);

        $response = $this->postJson('/api/monitor/tasks/retry-batch', [
            'task_ids' => ['task-batch-1', 'task-batch-2'],
        ]);

        $response->assertAccepted()
            ->assertJson([
                'accepted_count' => 2,
                'error_count' => 0,
            ]);

        $this->assertSame(2, WorkerTask::query()->whereNotNull('parent_task_id')->count());
    }
}
