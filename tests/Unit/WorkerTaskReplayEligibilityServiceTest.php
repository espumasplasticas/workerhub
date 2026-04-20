<?php

namespace Tests\Unit;

use App\Models\WorkerTask;
use App\Services\Workers\Orders\OrderPrototypeRepository;
use App\Services\Workers\Orders\OrderSiesaStateService;
use App\Services\Workers\Receipts\ReceiptPrototypeRepository;
use App\Services\Workers\Receipts\ReceiptSiesaStateService;
use App\Services\Workers\WorkerTaskReplayEligibilityService;
use Mockery;
use Tests\TestCase;

class WorkerTaskReplayEligibilityServiceTest extends TestCase
{
    public function test_it_blocks_replay_when_order_already_exists_in_siesa(): void
    {
        $task = new WorkerTask([
            'id' => 'task-order-1',
            'type' => 'order_migration',
            'status' => 'failed',
            'payload' => [
                'db_connection' => 'sqlsrv',
                'operational_center' => '002',
                'document_type' => 'FC',
                'document_number' => '24116',
            ],
        ]);

        $orderRepository = Mockery::mock(OrderPrototypeRepository::class);
        $orderRepository->shouldReceive('findHeader')->once()->andReturn((object) [
            'f430_id_co' => '002',
            'f430_id_tipo_docto' => 'PFC',
            'f430_consec_docto' => '24116',
        ]);

        $orderSiesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $orderSiesaStateService->shouldReceive('fetch')
            ->once()
            ->andReturn(new \App\Data\Orders\OrderSiesaStateSnapshot('002', 'FC', '24116', true, '002', 'PFC', '24116'));

        $receiptRepository = Mockery::mock(ReceiptPrototypeRepository::class);
        $receiptRepository->shouldNotReceive('findHeader');

        $receiptSiesaStateService = Mockery::mock(ReceiptSiesaStateService::class);
        $receiptSiesaStateService->shouldNotReceive('fetch');

        $service = new WorkerTaskReplayEligibilityService(
            $orderRepository,
            $orderSiesaStateService,
            $receiptRepository,
            $receiptSiesaStateService
        );

        $result = $service->inspect($task);

        $this->assertFalse($result['can_retry']);
        $this->assertSame('El pedido ya existe en Siesa y no debe reencolarse.', $result['reason']);
        $this->assertTrue($result['siesa_state']['exists']);
    }

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

        $orderRepository = Mockery::mock(OrderPrototypeRepository::class);
        $orderRepository->shouldNotReceive('findHeader');

        $orderSiesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $orderSiesaStateService->shouldNotReceive('fetch');

        $receiptRepository = Mockery::mock(ReceiptPrototypeRepository::class);
        $receiptRepository->shouldReceive('findHeader')->once()->andReturn((object) [
            'F350_ID_CO' => '001',
            'F350_ID_TIPO_DOCTO' => 'RX',
            'F350_CONSEC_DOCTO' => '1001',
        ]);

        $receiptSiesaStateService = Mockery::mock(ReceiptSiesaStateService::class);
        $receiptSiesaStateService->shouldReceive('fetch')
            ->once()
            ->andReturn(new \App\Data\Receipts\ReceiptSiesaStateSnapshot('001', 'RX', '1001', true, '001', 'RX', '1001'));

        $service = new WorkerTaskReplayEligibilityService(
            $orderRepository,
            $orderSiesaStateService,
            $receiptRepository,
            $receiptSiesaStateService
        );
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

        $orderRepository = Mockery::mock(OrderPrototypeRepository::class);
        $orderRepository->shouldNotReceive('findHeader');

        $orderSiesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $orderSiesaStateService->shouldNotReceive('fetch');

        $receiptRepository = Mockery::mock(ReceiptPrototypeRepository::class);
        $receiptRepository->shouldNotReceive('findHeader');

        $receiptSiesaStateService = Mockery::mock(ReceiptSiesaStateService::class);
        $receiptSiesaStateService->shouldNotReceive('fetch');

        $service = new WorkerTaskReplayEligibilityService(
            $orderRepository,
            $orderSiesaStateService,
            $receiptRepository,
            $receiptSiesaStateService
        );
        $result = $service->inspect($task);

        $this->assertTrue($result['can_retry']);
        $this->assertNull($result['reason']);
        $this->assertNull($result['siesa_state']);
    }
}
