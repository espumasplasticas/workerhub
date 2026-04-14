<?php

namespace Tests\Feature;

use App\Jobs\DispatchWorkerTaskJob;
use App\Services\Kafka\KafkaMessageProducer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ReceiptMigrationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accepts_and_persists_a_receipt_migration_task(): void
    {
        Queue::fake();

        config()->set('workerhub.kafka.publish_enabled', false);
        config()->set('workerhub.kafka.direct_dispatch_fallback', true);

        $producer = Mockery::mock(KafkaMessageProducer::class);
        $producer->shouldReceive('publish')->once()->andReturn(false);
        $this->app->instance(KafkaMessageProducer::class, $producer);

        $response = $this->postJson('/api/receipt-migrations', [
            'receipt_id' => 123,
            'document_id' => '001-RX-1001',
            'db_connection' => 'sqlsrv',
            'operational_center' => '001',
            'document_type' => 'RX',
            'document_number' => '1001',
            'company_id' => 1,
            'client_code' => '900123',
            'source' => 'api',
            'process_key' => 'receipts',
            'process_label' => 'Recibos',
            'schedule_name' => 'API_RECEIPT_CREATED',
            'task_name' => 'Migracion recibo POS',
        ]);

        $response->assertAccepted()
            ->assertJsonPath('dispatch_mode', 'direct_queue')
            ->assertJsonPath('queue', config('workerhub.tasks.receipt_migration.queue'));

        $taskId = $response->json('task_id');

        $this->assertDatabaseHas('worker_tasks', [
            'id' => $taskId,
            'type' => 'receipt_migration',
            'status' => 'queued',
            'source' => 'api',
            'kafka_key' => '001-RX-1001',
            'queue' => config('workerhub.tasks.receipt_migration.queue'),
        ]);

        $this->assertDatabaseHas('worker_task_events', [
            'worker_task_id' => $taskId,
            'event' => 'task.queued',
        ]);

        Queue::assertPushed(DispatchWorkerTaskJob::class, 1);
    }
}
