<?php

namespace Tests\Unit;

use App\Models\WorkerTask;
use App\Services\Workers\Receipts\ReceiptPrototypeRepository;
use App\Services\Workers\Receipts\ReceiptSiesaStateService;
use App\Services\Workers\WorkerTaskReplayEligibilityService;
use Mockery;
use Tests\TestCase;

class WorkerTaskReplayEligibilityServiceTest extends TestCase
{
    public function test_it_blocks_replay_when_receipt_already_exists_in_siesa(): void
    {
        $task = new WorkerTask([
            'id' => 'task-1',
            'type' => 'receipt_migration',
            'status' => 'failed',
            'payload' => [
                'db_connection' => 'sqlsrv',
                'operational_center' => '001',
                'document_type' => 'RX',
                'document_number' => '1001',
            ],
        ]);

        $repository = Mockery::mock(ReceiptPrototypeRepository::class);
        $repository->shouldReceive('findHeader')->once()->andReturn((object) [
            'F350_ID_CO' => '001',
            'F350_ID_TIPO_DOCTO' => 'RX',
            'F350_CONSEC_DOCTO' => '1001',
        ]);

        $siesaStateService = Mockery::mock(ReceiptSiesaStateService::class);
        $siesaStateService->shouldReceive('fetch')
            ->once()
            ->andReturn(new \App\Data\Receipts\ReceiptSiesaStateSnapshot('001', 'RX', '1001', true, '001', 'RX', '1001'));

        $service = new WorkerTaskReplayEligibilityService($repository, $siesaStateService);
        $result = $service->inspect($task);

        $this->assertFalse($result['can_retry']);
        $this->assertSame('El recibo ya existe en Siesa y no debe reencolarse.', $result['reason']);
        $this->assertTrue($result['siesa_state']['exists']);
    }

    public function test_it_allows_replay_for_non_receipt_failed_tasks(): void
    {
        $task = new WorkerTask([
            'id' => 'task-2',
            'type' => 'document_migration',
            'status' => 'failed',
        ]);

        $repository = Mockery::mock(ReceiptPrototypeRepository::class);
        $repository->shouldNotReceive('findHeader');

        $siesaStateService = Mockery::mock(ReceiptSiesaStateService::class);
        $siesaStateService->shouldNotReceive('fetch');

        $service = new WorkerTaskReplayEligibilityService($repository, $siesaStateService);
        $result = $service->inspect($task);

        $this->assertTrue($result['can_retry']);
        $this->assertNull($result['reason']);
        $this->assertNull($result['siesa_state']);
    }
}
