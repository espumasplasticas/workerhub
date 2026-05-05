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

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('workerhub.operations.allow_local_bypass', true);
        config()->set('workerhub.operations.access_token', '');
    }

    public function test_it_returns_monitor_summary(): void
    {
        WorkerTask::query()->create([
            'id' => 'task-1',
            'type' => 'document_migration',
            'source' => 'crm',
            'status' => 'completed',
            'priority' => 'default',
            'metadata' => [
                'process_key' => 'receipts',
                'process_label' => 'Recibos',
            ],
        ]);

        WorkerTask::query()->create([
            'id' => 'task-2',
            'type' => 'document_migration',
            'source' => 'erp',
            'status' => 'failed',
            'priority' => 'high',
            'metadata' => [
                'process_key' => 'sales_orders',
                'process_label' => 'Pedidos',
            ],
        ]);

        $response = $this->getJson('/api/monitor/tasks/summary');

        $response->assertOk()
            ->assertJson([
                'total' => 2,
                'completed' => 1,
                'failed' => 1,
            ])
            ->assertJsonPath('processes.0.key', 'receipts');
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

    public function test_it_exports_filtered_tasks(): void
    {
        WorkerTask::query()->create([
            'id' => 'task-export-default',
            'type' => 'document_migration',
            'source' => 'crm',
            'status' => 'failed',
            'priority' => 'default',
            'metadata' => [
                'process_key' => 'receipts',
                'process_label' => 'Recibos',
            ],
        ]);

        WorkerTask::query()->create([
            'id' => 'task-export-high',
            'type' => 'document_migration',
            'source' => 'crm',
            'status' => 'failed',
            'priority' => 'high',
            'metadata' => [
                'process_key' => 'invoices',
                'process_label' => 'Facturas',
            ],
        ]);

        $response = $this->getJson('/api/monitor/tasks/export?status=failed&priority=high&process_key=invoices');

        $response->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.id', 'task-export-high')
            ->assertJsonPath('items.0.process_label', 'Facturas');

        $this->assertDatabaseHas('worker_operation_logs', [
            'action' => 'monitor.tasks.export',
            'status' => 'success',
        ]);
    }

    public function test_it_exports_dead_letters_as_json(): void
    {
        WorkerTask::query()->create([
            'id' => 'task-export',
            'type' => 'document_migration',
            'source' => 'crm',
            'status' => 'failed',
            'priority' => 'high',
            'payload' => ['document_id' => 'DOC-EXPORT'],
        ]);

        $response = $this->getJson('/api/monitor/dead-letters/export');

        $response->assertOk()
            ->assertJson([
                'count' => 1,
            ])
            ->assertJsonPath('items.0.id', 'task-export')
            ->assertJsonPath('items.0.payload.document_id', 'DOC-EXPORT');

        $this->assertDatabaseHas('worker_operation_logs', [
            'action' => 'dead_letters.export',
            'status' => 'success',
        ]);
    }

    public function test_it_returns_recent_operation_logs(): void
    {
        WorkerTask::query()->create([
            'id' => 'task-actions',
            'type' => 'document_migration',
            'status' => 'failed',
            'priority' => 'default',
            'metadata' => [
                'process_key' => 'customers',
                'process_label' => 'Clientes',
                'schedule_name' => 'ImportarClientesCada10',
            ],
        ]);

        $this->getJson('/api/monitor/tasks/task-actions')
            ->assertOk()
            ->assertJsonPath('process_label', 'Clientes')
            ->assertJsonPath('schedule_name', 'ImportarClientesCada10');

        $response = $this->getJson('/api/monitor/actions');

        $response->assertOk();

        $this->assertTrue(
            collect($response->json('data'))->contains(
                fn (array $item): bool => $item['action'] === 'monitor.tasks.show' && $item['worker_task_id'] === 'task-actions'
            )
        );

        $this->assertTrue(
            collect($response->json('data'))->contains(
                fn (array $item): bool => $item['action'] === 'monitor.actions.index'
            )
        );
    }

    public function test_it_returns_task_lineage(): void
    {
        WorkerTask::query()->create([
            'id' => 'task-root',
            'type' => 'document_migration',
            'status' => 'failed',
            'priority' => 'default',
        ]);

        WorkerTask::query()->create([
            'id' => 'task-child',
            'parent_task_id' => 'task-root',
            'type' => 'document_migration',
            'status' => 'completed',
            'priority' => 'high',
        ]);

        $response = $this->getJson('/api/monitor/tasks/task-child/lineage');

        $response->assertOk()
            ->assertJsonPath('requested_task_id', 'task-child')
            ->assertJsonPath('root_task_id', 'task-root')
            ->assertJsonPath('lineage.id', 'task-root')
            ->assertJsonPath('lineage.children.0.id', 'task-child');

        $this->assertDatabaseHas('worker_operation_logs', [
            'action' => 'monitor.tasks.lineage',
            'worker_task_id' => 'task-child',
        ]);
    }

    public function test_it_replays_a_failed_task_and_creates_a_child_task(): void
    {
        $producer = Mockery::mock(KafkaMessageProducer::class);
        $producer->shouldReceive('publish')->once()->andReturn(true);
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

        $this->assertDatabaseHas('worker_operation_logs', [
            'action' => 'task.retry',
            'status' => 'success',
            'worker_task_id' => 'task-dead-letter',
        ]);
    }

    public function test_it_replays_multiple_failed_tasks_in_batch(): void
    {
        $producer = Mockery::mock(KafkaMessageProducer::class);
        $producer->shouldReceive('publish')->twice()->andReturn(true);
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

        $this->assertDatabaseHas('worker_operation_logs', [
            'action' => 'task.retry_batch',
            'status' => 'success',
        ]);
    }

    public function test_it_replays_tasks_using_current_filters(): void
    {
        $producer = Mockery::mock(KafkaMessageProducer::class);
        $producer->shouldReceive('publish')->once()->andReturn(true);
        $this->app->instance(KafkaMessageProducer::class, $producer);

        WorkerTask::query()->create([
            'id' => 'task-filter-default',
            'type' => 'document_migration',
            'source' => 'crm',
            'status' => 'failed',
            'priority' => 'default',
        ]);

        WorkerTask::query()->create([
            'id' => 'task-filter-high-1',
            'type' => 'document_migration',
            'source' => 'crm',
            'status' => 'failed',
            'priority' => 'high',
        ]);

        WorkerTask::query()->create([
            'id' => 'task-filter-high-2',
            'type' => 'document_migration',
            'source' => 'crm',
            'status' => 'rejected',
            'priority' => 'high',
        ]);

        $response = $this->postJson('/api/monitor/tasks/retry-filtered', [
            'status' => 'failed',
            'priority' => 'high',
            'source' => 'crm',
            'limit' => 50,
        ]);

        $response->assertAccepted()
            ->assertJson([
                'matched_count' => 1,
                'accepted_count' => 1,
                'error_count' => 0,
            ]);

        $this->assertSame(1, WorkerTask::query()->whereNotNull('parent_task_id')->count());

        $this->assertDatabaseHas('worker_operation_logs', [
            'action' => 'task.retry_filtered',
            'status' => 'success',
        ]);
    }
}
