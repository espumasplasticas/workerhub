<?php

namespace Tests\Feature;

use App\Jobs\DispatchWorkerTaskJob;
use App\Services\Workers\Receipts\ReceiptLegacyStateService;
use App\Services\Workers\Receipts\ReceiptPrototypeRepository;
use App\Services\Workers\Receipts\ReceiptSiesaStateService;
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

        $repository = Mockery::mock(ReceiptPrototypeRepository::class);
        $repository->shouldReceive('hydratePayloadFromReceiptId')->once()->andReturn([
            'receipt_id' => 123,
            'document_id' => '001-RX-1001',
            'db_connection' => 'sqlsrv',
            'operational_center' => '001',
            'document_type' => 'RX',
            'document_number' => '1001',
            'company_id' => 1,
            'client_code' => '900123',
            'source' => 'api',
            'metadata' => [
                'process_key' => 'receipts',
                'process_label' => 'Recibos',
                'schedule_name' => 'API_RECEIPT_CREATED',
                'task_name' => 'Migracion recibo POS',
            ],
        ]);
        $repository->shouldReceive('findHeader')->once()->andReturn((object) [
            'F350_ID_CO' => '001',
            'F350_ID_TIPO_DOCTO' => 'RX',
            'F350_CONSEC_DOCTO' => '1001',
        ]);
        $this->app->instance(ReceiptPrototypeRepository::class, $repository);

        $siesaStateService = Mockery::mock(ReceiptSiesaStateService::class);
        $siesaStateService->shouldReceive('fetch')
            ->once()
            ->andReturn(new \App\Data\Receipts\ReceiptSiesaStateSnapshot('001', 'RX', '1001', false));
        $this->app->instance(ReceiptSiesaStateService::class, $siesaStateService);

        $legacyState = Mockery::mock(ReceiptLegacyStateService::class);
        $legacyState->shouldNotReceive('markDetectedInSiesa');
        $this->app->instance(ReceiptLegacyStateService::class, $legacyState);

        $producer = Mockery::mock(KafkaMessageProducer::class);
        $producer->shouldReceive('publish')->once()->andReturn(false);
        $this->app->instance(KafkaMessageProducer::class, $producer);

        $response = $this->postJson('/api/receipt-migrations', [
            'receipt_id' => 123,
            'db_connection' => 'sqlsrv',
            'company_id' => 1,
            'source' => 'api',
            'process_key' => 'receipts',
            'process_label' => 'Recibos',
            'schedule_name' => 'API_RECEIPT_CREATED',
            'task_name' => 'Migracion recibo POS',
        ]);

        $expectedQueue = (string) config('workerhub.processes.receipts.queues.default');

        $response->assertAccepted()
            ->assertJsonPath('dispatch_mode', 'direct_queue')
            ->assertJsonPath('queue', $expectedQueue);

        $taskId = $response->json('task_id');

        $this->assertDatabaseHas('worker_tasks', [
            'id' => $taskId,
            'type' => 'receipt_migration',
            'status' => 'queued',
            'source' => 'api',
            'kafka_key' => '001-RX-1001',
            'queue' => $expectedQueue,
        ]);

        $this->assertDatabaseHas('worker_task_events', [
            'worker_task_id' => $taskId,
            'event' => 'task.queued',
        ]);

        Queue::assertPushed(DispatchWorkerTaskJob::class, 1);
    }

    public function test_it_rejects_enqueue_when_the_receipt_already_exists_in_siesa(): void
    {
        Queue::fake();

        $repository = Mockery::mock(ReceiptPrototypeRepository::class);
        $repository->shouldReceive('hydratePayloadFromReceiptId')->once()->andReturn([
            'receipt_id' => 123,
            'document_id' => '001-RX-1001',
            'db_connection' => 'sqlsrv',
            'operational_center' => '001',
            'document_type' => 'RX',
            'document_number' => '1001',
            'source' => 'api',
        ]);
        $repository->shouldReceive('findHeader')->once()->andReturn((object) [
            'F350_ID_CO' => '001',
            'F350_ID_TIPO_DOCTO' => 'RX',
            'F350_CONSEC_DOCTO' => '1001',
        ]);
        $this->app->instance(ReceiptPrototypeRepository::class, $repository);

        $siesaStateService = Mockery::mock(ReceiptSiesaStateService::class);
        $siesaStateService->shouldReceive('fetch')
            ->once()
            ->andReturn(new \App\Data\Receipts\ReceiptSiesaStateSnapshot('001', 'RX', '1001', true, '001', 'RX', '1001'));
        $this->app->instance(ReceiptSiesaStateService::class, $siesaStateService);

        $legacyState = Mockery::mock(ReceiptLegacyStateService::class);
        $legacyState->shouldReceive('markDetectedInSiesa')->once();
        $this->app->instance(ReceiptLegacyStateService::class, $legacyState);

        $response = $this->postJson('/api/receipt-migrations', [
            'receipt_id' => 123,
            'db_connection' => 'sqlsrv',
            'source' => 'api',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('siesa_state.exists', true);

        $this->assertDatabaseCount('worker_tasks', 0);
        Queue::assertNothingPushed();
    }
}
