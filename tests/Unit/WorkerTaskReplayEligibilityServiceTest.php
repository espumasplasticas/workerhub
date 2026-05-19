<?php

namespace Tests\Unit;

use App\Models\WorkerTask;
use App\Services\Workers\Invoices\InvoicePrototypeRepository;
use App\Services\Workers\Invoices\InvoiceSiesaStateService;
use App\Services\Workers\Orders\OrderDeliveryGenerationRepository;
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

        $invoiceRepository = Mockery::mock(InvoicePrototypeRepository::class);
        $invoiceRepository->shouldNotReceive('findHeader');

        $invoiceSiesaStateService = Mockery::mock(InvoiceSiesaStateService::class);
        $invoiceSiesaStateService->shouldNotReceive('fetch');

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

        $orderDeliveryRepository = Mockery::mock(OrderDeliveryGenerationRepository::class);
        $orderDeliveryRepository->shouldNotReceive('shouldGenerateDomicile');
        $orderDeliveryRepository->shouldNotReceive('findActiveDomicileForEnterpriseOrder');

        $receiptRepository = Mockery::mock(ReceiptPrototypeRepository::class);
        $receiptRepository->shouldNotReceive('findHeader');

        $receiptSiesaStateService = Mockery::mock(ReceiptSiesaStateService::class);
        $receiptSiesaStateService->shouldNotReceive('fetch');

        $service = new WorkerTaskReplayEligibilityService(
            $invoiceRepository,
            $invoiceSiesaStateService,
            $orderDeliveryRepository,
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

    public function test_it_blocks_order_cancellation_replay_when_order_was_already_fulfilled_in_siesa(): void
    {
        $task = new WorkerTask([
            'id' => 'task-order-cancel-1',
            'type' => 'order_cancellation',
            'status' => 'failed',
            'payload' => [
                'db_connection' => 'sqlsrv',
                'operational_center' => '002',
                'document_type' => 'FC',
                'document_number' => '24116',
            ],
        ]);

        $invoiceRepository = Mockery::mock(InvoicePrototypeRepository::class);
        $invoiceRepository->shouldNotReceive('findHeader');

        $invoiceSiesaStateService = Mockery::mock(InvoiceSiesaStateService::class);
        $invoiceSiesaStateService->shouldNotReceive('fetch');

        $orderRepository = Mockery::mock(OrderPrototypeRepository::class);
        $orderRepository->shouldReceive('findHeader')->once()->andReturn((object) [
            'f430_id_co' => '002',
            'f430_id_tipo_docto' => 'PFC',
            'f430_consec_docto' => '24116',
        ]);

        $orderSiesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $orderSiesaStateService->shouldReceive('fetch')
            ->once()
            ->andReturn(new \App\Data\Orders\OrderSiesaStateSnapshot('002', 'FC', '24116', true, '002', 'PFC', '24116', 99, 120000.0, 4));

        $orderDeliveryRepository = Mockery::mock(OrderDeliveryGenerationRepository::class);
        $orderDeliveryRepository->shouldNotReceive('shouldGenerateDomicile');
        $orderDeliveryRepository->shouldNotReceive('findActiveDomicileForEnterpriseOrder');

        $receiptRepository = Mockery::mock(ReceiptPrototypeRepository::class);
        $receiptRepository->shouldNotReceive('findHeader');

        $receiptSiesaStateService = Mockery::mock(ReceiptSiesaStateService::class);
        $receiptSiesaStateService->shouldNotReceive('fetch');

        $service = new WorkerTaskReplayEligibilityService(
            $invoiceRepository,
            $invoiceSiesaStateService,
            $orderDeliveryRepository,
            $orderRepository,
            $orderSiesaStateService,
            $receiptRepository,
            $receiptSiesaStateService
        );

        $result = $service->inspect($task);

        $this->assertFalse($result['can_retry']);
        $this->assertSame('El pedido ya esta cumplido en Siesa y no debe reencolarse.', $result['reason']);
        $this->assertSame(4, $result['siesa_state']['state_indicator']);
    }

    public function test_it_allows_receipt_migration_replay_when_receipt_already_exists_in_siesa(): void
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

        $invoiceRepository = Mockery::mock(InvoicePrototypeRepository::class);
        $invoiceRepository->shouldNotReceive('findHeader');

        $invoiceSiesaStateService = Mockery::mock(InvoiceSiesaStateService::class);
        $invoiceSiesaStateService->shouldNotReceive('fetch');

        $orderRepository = Mockery::mock(OrderPrototypeRepository::class);
        $orderRepository->shouldNotReceive('findHeader');

        $orderSiesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $orderSiesaStateService->shouldNotReceive('fetch');

        $orderDeliveryRepository = Mockery::mock(OrderDeliveryGenerationRepository::class);
        $orderDeliveryRepository->shouldNotReceive('shouldGenerateDomicile');
        $orderDeliveryRepository->shouldNotReceive('findActiveDomicileForEnterpriseOrder');

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
            $invoiceRepository,
            $invoiceSiesaStateService,
            $orderDeliveryRepository,
            $orderRepository,
            $orderSiesaStateService,
            $receiptRepository,
            $receiptSiesaStateService
        );
        $result = $service->inspect($task);

        $this->assertTrue($result['can_retry']);
        $this->assertNull($result['reason']);
        $this->assertTrue($result['siesa_state']['exists']);
    }

    public function test_it_allows_receipt_cancellation_replay_when_receipt_is_already_cancelled_in_siesa(): void
    {
        $task = new WorkerTask([
            'id' => 'task-receipt-cancel-1',
            'type' => 'receipt_cancellation',
            'status' => 'failed',
            'payload' => [
                'db_connection' => 'sqlsrv',
                'operational_center' => '002',
                'document_type' => 'B2',
                'document_number' => '9828',
            ],
        ]);

        $invoiceRepository = Mockery::mock(InvoicePrototypeRepository::class);
        $invoiceRepository->shouldNotReceive('findHeader');

        $invoiceSiesaStateService = Mockery::mock(InvoiceSiesaStateService::class);
        $invoiceSiesaStateService->shouldNotReceive('fetch');

        $orderRepository = Mockery::mock(OrderPrototypeRepository::class);
        $orderRepository->shouldNotReceive('findHeader');

        $orderSiesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $orderSiesaStateService->shouldNotReceive('fetch');

        $orderDeliveryRepository = Mockery::mock(OrderDeliveryGenerationRepository::class);
        $orderDeliveryRepository->shouldNotReceive('shouldGenerateDomicile');
        $orderDeliveryRepository->shouldNotReceive('findActiveDomicileForEnterpriseOrder');

        $receiptRepository = Mockery::mock(ReceiptPrototypeRepository::class);
        $receiptRepository->shouldReceive('findHeader')->once()->andReturn((object) [
            'F350_ID_CO' => 'S01',
            'F350_ID_TIPO_DOCTO' => 'RB2',
            'F350_CONSEC_DOCTO' => '9828',
        ]);

        $receiptSiesaStateService = Mockery::mock(ReceiptSiesaStateService::class);
        $receiptSiesaStateService->shouldReceive('fetch')
            ->once()
            ->andReturn(new \App\Data\Receipts\ReceiptSiesaStateSnapshot('002', 'B2', '9828', true, 'S01', 'RB2', '9828', 0.0, 0.0, 2));

        $service = new WorkerTaskReplayEligibilityService(
            $invoiceRepository,
            $invoiceSiesaStateService,
            $orderDeliveryRepository,
            $orderRepository,
            $orderSiesaStateService,
            $receiptRepository,
            $receiptSiesaStateService
        );
        $result = $service->inspect($task);

        $this->assertTrue($result['can_retry']);
        $this->assertNull($result['reason']);
        $this->assertTrue($result['siesa_state']['exists']);
        $this->assertSame(2, $result['siesa_state']['state_indicator']);
    }

    public function test_it_allows_replay_for_non_receipt_failed_tasks(): void
    {
        $task = new WorkerTask([
            'id' => 'task-2',
            'type' => 'document_migration',
            'status' => 'failed',
        ]);

        $invoiceRepository = Mockery::mock(InvoicePrototypeRepository::class);
        $invoiceRepository->shouldNotReceive('findHeader');

        $invoiceSiesaStateService = Mockery::mock(InvoiceSiesaStateService::class);
        $invoiceSiesaStateService->shouldNotReceive('fetch');

        $orderRepository = Mockery::mock(OrderPrototypeRepository::class);
        $orderRepository->shouldNotReceive('findHeader');

        $orderSiesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $orderSiesaStateService->shouldNotReceive('fetch');

        $orderDeliveryRepository = Mockery::mock(OrderDeliveryGenerationRepository::class);
        $orderDeliveryRepository->shouldNotReceive('shouldGenerateDomicile');
        $orderDeliveryRepository->shouldNotReceive('findActiveDomicileForEnterpriseOrder');

        $receiptRepository = Mockery::mock(ReceiptPrototypeRepository::class);
        $receiptRepository->shouldNotReceive('findHeader');

        $receiptSiesaStateService = Mockery::mock(ReceiptSiesaStateService::class);
        $receiptSiesaStateService->shouldNotReceive('fetch');

        $service = new WorkerTaskReplayEligibilityService(
            $invoiceRepository,
            $invoiceSiesaStateService,
            $orderDeliveryRepository,
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

    public function test_it_blocks_replay_for_order_delivery_generation_when_the_order_already_has_an_active_domicile(): void
    {
        $task = new WorkerTask([
            'id' => 'task-order-delivery-1',
            'type' => 'order_delivery_generation',
            'status' => 'failed',
            'payload' => [
                'db_connection' => 'sqlsrv',
                'operational_center' => '002',
                'document_type' => 'FC',
                'document_number' => '24116',
            ],
        ]);

        $invoiceRepository = Mockery::mock(InvoicePrototypeRepository::class);
        $invoiceRepository->shouldNotReceive('findHeader');

        $invoiceSiesaStateService = Mockery::mock(InvoiceSiesaStateService::class);
        $invoiceSiesaStateService->shouldNotReceive('fetch');

        $orderRepository = Mockery::mock(OrderPrototypeRepository::class);
        $orderRepository->shouldReceive('findOrderRecord')->once()->andReturn((object) [
            'PE_IndicadorAnulado' => 0,
            'PE_EstadoVerificadoExportacion' => 2,
            'PE_Perfil' => 1,
            'PE_FechaDocumento' => 20260424,
        ]);
        $orderRepository->shouldReceive('findHeader')->once()->andReturn((object) [
            'f430_id_co' => '002',
            'f430_id_tipo_docto' => 'PFC',
            'f430_consec_docto' => '24116',
        ]);

        $orderSiesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $orderSiesaStateService->shouldReceive('fetch')
            ->once()
            ->andReturn(new \App\Data\Orders\OrderSiesaStateSnapshot('002', 'FC', '24116', true, '002', 'PFC', '24116', 99, 120000.0, 1));

        $orderDeliveryRepository = Mockery::mock(OrderDeliveryGenerationRepository::class);
        $orderDeliveryRepository->shouldReceive('shouldGenerateDomicile')->once()->andReturnTrue();
        $orderDeliveryRepository->shouldReceive('findActiveDomicileForEnterpriseOrder')
            ->once()
            ->andReturn((object) ['DP_TipoId' => 'DMED', 'DP_Id' => 12345]);

        $receiptRepository = Mockery::mock(ReceiptPrototypeRepository::class);
        $receiptRepository->shouldNotReceive('findHeader');

        $receiptSiesaStateService = Mockery::mock(ReceiptSiesaStateService::class);
        $receiptSiesaStateService->shouldNotReceive('fetch');

        $service = new WorkerTaskReplayEligibilityService(
            $invoiceRepository,
            $invoiceSiesaStateService,
            $orderDeliveryRepository,
            $orderRepository,
            $orderSiesaStateService,
            $receiptRepository,
            $receiptSiesaStateService
        );

        $result = $service->inspect($task);

        $this->assertFalse($result['can_retry']);
        $this->assertSame('El pedido ya tiene un domicilio activo y no debe reencolarse.', $result['reason']);
        $this->assertTrue($result['siesa_state']['exists']);
    }
}
