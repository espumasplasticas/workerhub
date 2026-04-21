<?php

namespace Tests\Unit;

use App\Data\Orders\OrderSiesaStateSnapshot;
use App\Data\Receipts\ReceiptCustomerSyncSnapshot;
use App\Data\SiesaWebServiceLogRecord;
use App\Exceptions\WorkerTaskProcessingException;
use App\Services\Workers\EpsaSoapConfigurationValidator;
use App\Services\Workers\OrderMigrationService;
use App\Services\Workers\Orders\OrderCashConversionService;
use App\Services\Workers\Orders\OrderCustomerSyncService;
use App\Services\Workers\Orders\OrderLegacyStateService;
use App\Services\Workers\Orders\OrderLineFactory;
use App\Services\Workers\Orders\OrderPreMigrationGuard;
use App\Services\Workers\Orders\OrderPrototypeRepository;
use App\Services\Workers\Orders\OrderSiesaStateService;
use App\Services\Workers\SiesaImportAuditResult;
use App\Services\Workers\SiesaImportAuditService;
use Epsalibrary\Results\ImportResult;
use Mockery;
use stdClass;
use Tests\TestCase;

class OrderMigrationServiceTest extends TestCase
{
    public function test_it_imports_an_order_and_marks_it_verified_when_siesa_totals_match(): void
    {
        $header = (object) [
            'PE_CentroOperativo' => '002',
            'PE_TipoDocumento' => 'FC',
            'PE_NumeroDocumento' => '24116',
            'PE_IndicadorObsequio' => 0,
            'f430_id_co' => '002',
            'f430_id_tipo_docto' => 'PFC',
            'f430_consec_docto' => '24116',
        ];

        $details = [
            (object) ['PD_CodigoItem' => '1001', 'PD_Referencia' => 'REF-1'],
            (object) ['PD_CodigoItem' => '1002', 'PD_Referencia' => 'REF-2'],
        ];
        $orderRecord = (object) [
            'PE_OrdenDeCompra' => 'OC-24116',
            'PE_OrdenDeCargue' => 'LOAD-24116',
        ];

        $repository = Mockery::mock(OrderPrototypeRepository::class);
        $repository->shouldReceive('findHeader')->once()->andReturn($header);
        $repository->shouldReceive('findOrderRecord')->once()->andReturn($orderRecord);
        $repository->shouldReceive('findDetails')->once()->andReturn($details);

        $cashConversion = Mockery::mock(OrderCashConversionService::class);
        $cashConversion->shouldReceive('normalizeIfSupported')->once()->andReturn(false);

        $validator = Mockery::mock(EpsaSoapConfigurationValidator::class);
        $validator->shouldReceive('validate')->once();

        $guard = Mockery::mock(OrderPreMigrationGuard::class);
        $guard->shouldReceive('assertCanMigrate')
            ->once()
            ->with(
                Mockery::type('array'),
                $orderRecord
            )
            ->andReturn([
                'client_code' => '900123',
                'client_branch' => '001',
                'printed' => true,
                'customer_class' => '01',
                'is_cancelled' => false,
                'is_manual_request' => false,
                'is_gift' => false,
            ]);

        $customerSync = Mockery::mock(OrderCustomerSyncService::class);
        $customerSync->shouldReceive('sync')
            ->once()
            ->andReturn([
                'status' => 'prepared',
                'line_count' => 12,
                'lines' => array_fill(0, 12, '0200...'),
            ]);

        $lineFactory = Mockery::mock(OrderLineFactory::class);
        $lineFactory->shouldReceive('build')
            ->once()
            ->with(
                Mockery::type('array'),
                $header,
                $orderRecord,
                $details
            )
            ->andReturn(['0430...', '0431...', '0432...']);

        $siesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $siesaStateService->shouldReceive('fetch')
            ->twice()
            ->andReturn(
                new OrderSiesaStateSnapshot('002', 'FC', '24116', false),
                new OrderSiesaStateSnapshot('002', 'FC', '24116', true, '002', 'PFC', '24116', 99, 120000.0, 1)
            );

        $legacyState = Mockery::mock(OrderLegacyStateService::class);
        $legacyState->shouldReceive('markMigrationStarted')->once();
        $legacyState->shouldReceive('markMigrated')->once();
        $legacyState->shouldReceive('updateEnterpriseRowId')->once();
        $legacyState->shouldReceive('computeLegacyNetTotal')->once()->andReturn(120000.0);
        $legacyState->shouldReceive('verificationThreshold')->once()->andReturn(1000.0);
        $legacyState->shouldReceive('markVerified')->once();

        $auditService = Mockery::mock(SiesaImportAuditService::class);
        $auditService->shouldReceive('import')
            ->once()
            ->with(
                Mockery::on(static fn (array $lines): bool => count($lines) === 15),
                Mockery::on(static fn (array $context): bool => $context['task_type'] === 'order_migration'
                    && $context['document_id'] === '002-FC-24116'
                    && $context['line_count'] === 15
                    && $context['order_line_count'] === 3
                    && $context['customer_sync_line_count'] === 12
                    && $context['detail_count'] === 2)
            )
            ->andReturn(new SiesaImportAuditResult(
                new SiesaWebServiceLogRecord(44, '<Envelope />', ['import_stage' => 'order_migration']),
                new ImportResult(true, 'Pedido importado', [], '<Envelope />')
            ));

        $service = new OrderMigrationService(
            $auditService,
            $validator,
            $guard,
            $repository,
            $cashConversion,
            $customerSync,
            $lineFactory,
            $siesaStateService,
            $legacyState
        );

        $result = $service->handle([
            'document_id' => '002-FC-24116',
            'source' => 'api',
            'db_connection' => 'sqlsrv',
            'operational_center' => '002',
            'document_type' => 'FC',
            'document_number' => '24116',
        ]);

        $this->assertSame('002-FC-24116', $result['document_id']);
        $this->assertSame('Pedido importado', $result['message']);
        $this->assertSame(15, $result['line_count']);
        $this->assertSame(3, $result['order_line_count']);
        $this->assertSame(2, $result['detail_count']);
        $this->assertSame(120000.0, $result['legacy_net_total']);
        $this->assertTrue($result['siesa_state']['exists']);
    }

    public function test_it_stops_when_the_order_already_exists_in_siesa(): void
    {
        $repository = Mockery::mock(OrderPrototypeRepository::class);
        $repository->shouldReceive('findHeader')->once()->andReturn((object) [
            'f430_id_co' => '002',
            'f430_id_tipo_docto' => 'PFC',
            'f430_consec_docto' => '24116',
        ]);
        $repository->shouldReceive('findOrderRecord')->once()->andReturn((object) [
            'PE_OrdenDeCompra' => 'OC-24116',
        ]);
        $repository->shouldNotReceive('findDetails');

        $cashConversion = Mockery::mock(OrderCashConversionService::class);
        $cashConversion->shouldReceive('normalizeIfSupported')->once()->andReturn(false);

        $validator = Mockery::mock(EpsaSoapConfigurationValidator::class);
        $validator->shouldNotReceive('validate');

        $guard = Mockery::mock(OrderPreMigrationGuard::class);
        $guard->shouldReceive('assertCanMigrate')
            ->once()
            ->with(
                Mockery::type('array'),
                Mockery::type(stdClass::class)
            )
            ->andReturn([
                'client_code' => '900123',
                'printed' => true,
            ]);

        $customerSync = Mockery::mock(OrderCustomerSyncService::class);
        $customerSync->shouldNotReceive('sync');

        $lineFactory = Mockery::mock(OrderLineFactory::class);
        $lineFactory->shouldNotReceive('build');

        $siesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $siesaStateService->shouldReceive('fetch')
            ->once()
            ->andReturn(new OrderSiesaStateSnapshot('002', 'FC', '24116', true, '002', 'PFC', '24116', 99, 120000.0, 1));

        $legacyState = Mockery::mock(OrderLegacyStateService::class);
        $legacyState->shouldReceive('markDetectedInSiesa')->once();

        $auditService = Mockery::mock(SiesaImportAuditService::class);
        $auditService->shouldNotReceive('import');

        $service = new OrderMigrationService(
            $auditService,
            $validator,
            $guard,
            $repository,
            $cashConversion,
            $customerSync,
            $lineFactory,
            $siesaStateService,
            $legacyState
        );

        $this->expectException(WorkerTaskProcessingException::class);
        $this->expectExceptionMessage('El pedido ya existe en Siesa y no debe retransmitirse.');

        $service->handle([
            'document_id' => '002-FC-24116',
            'db_connection' => 'sqlsrv',
            'operational_center' => '002',
            'document_type' => 'FC',
            'document_number' => '24116',
        ]);
    }
}
