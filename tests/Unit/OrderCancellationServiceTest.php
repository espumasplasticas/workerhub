<?php

namespace Tests\Unit;

use App\Data\Orders\OrderSiesaStateSnapshot;
use App\Data\SiesaWebServiceLogRecord;
use App\Exceptions\WorkerTaskProcessingException;
use App\Services\Workers\EpsaSoapConfigurationValidator;
use App\Services\Workers\OrderCancellationService;
use App\Services\Workers\Orders\OrderCancellationCommitmentReleaseService;
use App\Services\Workers\Orders\OrderCancellationOperationalSideEffectsService;
use App\Services\Workers\Orders\OrderLegacyStateService;
use App\Services\Workers\Orders\OrderLineFactory;
use App\Services\Workers\Orders\OrderPrototypeRepository;
use App\Services\Workers\Orders\OrderSiesaStateService;
use App\Services\Workers\SiesaImportAuditResult;
use App\Services\Workers\SiesaImportAuditService;
use Epsalibrary\Results\ImportResult;
use Mockery;
use Tests\TestCase;

class OrderCancellationServiceTest extends TestCase
{
    public function test_it_descompromises_then_cancels_a_committed_order(): void
    {
        $repository = Mockery::mock(OrderPrototypeRepository::class);
        $repository->shouldReceive('findOrderRecord')->once()->andReturn((object) ['PE_RowId' => 10]);
        $repository->shouldReceive('findHeader')->once()->andReturn((object) [
            'f430_id_co' => '002',
            'f430_id_tipo_docto' => 'PFC',
            'f430_consec_docto' => '24116',
        ]);

        $validator = Mockery::mock(EpsaSoapConfigurationValidator::class);
        $validator->shouldReceive('validate')->once();

        $lineFactory = Mockery::mock(OrderLineFactory::class);
        $lineFactory->shouldReceive('buildCancellation')->once()->andReturn(['0430...']);

        $siesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $siesaStateService->shouldReceive('fetch')
            ->twice()
            ->andReturn(
                new OrderSiesaStateSnapshot('002', 'FC', '24116', true, '002', 'PFC', '24116', 99, 120000.0, 3),
                new OrderSiesaStateSnapshot('002', 'FC', '24116', true, '002', 'PFC', '24116', 99, 120000.0, 9)
            );

        $legacyState = Mockery::mock(OrderLegacyStateService::class);
        $legacyState->shouldReceive('markCancellationRequested')->once();
        $legacyState->shouldReceive('markCancelled')->once();

        $commitmentReleaseService = Mockery::mock(OrderCancellationCommitmentReleaseService::class);
        $commitmentReleaseService->shouldReceive('releaseIfCommitted')->once()->andReturn(2);

        $operationalSideEffectsService = Mockery::mock(OrderCancellationOperationalSideEffectsService::class);
        $operationalSideEffectsService->shouldReceive('applyPostCancellationSideEffects')->once();

        $auditService = Mockery::mock(SiesaImportAuditService::class);
        $auditService->shouldReceive('import')
            ->once()
            ->andReturn(new SiesaImportAuditResult(
                new SiesaWebServiceLogRecord(1, '<Envelope />', ['import_stage' => 'order_cancellation']),
                new ImportResult(true, 'Pedido anulado', [], '<Envelope />')
            ));

        $service = new OrderCancellationService(
            $auditService,
            $validator,
            $repository,
            $lineFactory,
            $siesaStateService,
            $legacyState,
            $commitmentReleaseService,
            $operationalSideEffectsService
        );

        $result = $service->handle([
            'document_id' => '002-FC-24116',
            'db_connection' => 'sqlsrv',
            'operational_center' => '002',
            'document_type' => 'FC',
            'document_number' => '24116',
        ]);

        $this->assertSame(2, $result['released_commitment_line_count']);
        $this->assertSame('Pedido anulado', $result['message']);
        $this->assertSame(9, $result['siesa_state']['state_indicator']);
    }

    public function test_it_returns_a_resolved_result_when_the_order_was_already_cancelled(): void
    {
        $repository = Mockery::mock(OrderPrototypeRepository::class);
        $repository->shouldReceive('findOrderRecord')->once()->andReturn((object) ['PE_RowId' => 10]);
        $repository->shouldReceive('findHeader')->once()->andReturn((object) [
            'f430_id_co' => '002',
            'f430_id_tipo_docto' => 'PFC',
            'f430_consec_docto' => '24116',
        ]);

        $validator = Mockery::mock(EpsaSoapConfigurationValidator::class);
        $validator->shouldNotReceive('validate');

        $lineFactory = Mockery::mock(OrderLineFactory::class);
        $lineFactory->shouldNotReceive('buildCancellation');

        $siesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $siesaStateService->shouldReceive('fetch')
            ->once()
            ->andReturn(new OrderSiesaStateSnapshot('002', 'FC', '24116', true, '002', 'PFC', '24116', 99, 120000.0, 9));

        $legacyState = Mockery::mock(OrderLegacyStateService::class);
        $legacyState->shouldReceive('markCancelled')->once();

        $commitmentReleaseService = Mockery::mock(OrderCancellationCommitmentReleaseService::class);
        $commitmentReleaseService->shouldNotReceive('releaseIfCommitted');

        $operationalSideEffectsService = Mockery::mock(OrderCancellationOperationalSideEffectsService::class);
        $operationalSideEffectsService->shouldReceive('applyPostCancellationSideEffects')->once();

        $auditService = Mockery::mock(SiesaImportAuditService::class);
        $auditService->shouldNotReceive('import');

        $service = new OrderCancellationService(
            $auditService,
            $validator,
            $repository,
            $lineFactory,
            $siesaStateService,
            $legacyState,
            $commitmentReleaseService,
            $operationalSideEffectsService
        );

        $result = $service->handle([
            'document_id' => '002-FC-24116',
            'db_connection' => 'sqlsrv',
            'operational_center' => '002',
            'document_type' => 'FC',
            'document_number' => '24116',
        ]);

        $this->assertTrue($result['already_cancelled']);
        $this->assertSame(0, $result['line_count']);
    }

    public function test_it_blocks_cancellation_when_the_order_is_already_fulfilled_in_siesa(): void
    {
        $repository = Mockery::mock(OrderPrototypeRepository::class);
        $repository->shouldReceive('findOrderRecord')->once()->andReturn((object) ['PE_RowId' => 10]);
        $repository->shouldReceive('findHeader')->once()->andReturn((object) [
            'f430_id_co' => '002',
            'f430_id_tipo_docto' => 'PFC',
            'f430_consec_docto' => '24116',
        ]);

        $validator = Mockery::mock(EpsaSoapConfigurationValidator::class);
        $validator->shouldNotReceive('validate');

        $lineFactory = Mockery::mock(OrderLineFactory::class);
        $lineFactory->shouldNotReceive('buildCancellation');

        $siesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $siesaStateService->shouldReceive('fetch')
            ->once()
            ->andReturn(new OrderSiesaStateSnapshot('002', 'FC', '24116', true, '002', 'PFC', '24116', 99, 120000.0, 4));

        $legacyState = Mockery::mock(OrderLegacyStateService::class);
        $legacyState->shouldNotReceive('markCancellationRequested');

        $commitmentReleaseService = Mockery::mock(OrderCancellationCommitmentReleaseService::class);
        $commitmentReleaseService->shouldNotReceive('releaseIfCommitted');

        $operationalSideEffectsService = Mockery::mock(OrderCancellationOperationalSideEffectsService::class);
        $operationalSideEffectsService->shouldNotReceive('applyPostCancellationSideEffects');

        $auditService = Mockery::mock(SiesaImportAuditService::class);
        $auditService->shouldNotReceive('import');

        $service = new OrderCancellationService(
            $auditService,
            $validator,
            $repository,
            $lineFactory,
            $siesaStateService,
            $legacyState,
            $commitmentReleaseService,
            $operationalSideEffectsService
        );

        $this->expectException(WorkerTaskProcessingException::class);
        $this->expectExceptionMessage('No se puede anular el pedido porque en Siesa ya esta cumplido.');

        $service->handle([
            'document_id' => '002-FC-24116',
            'db_connection' => 'sqlsrv',
            'operational_center' => '002',
            'document_type' => 'FC',
            'document_number' => '24116',
        ]);
    }
}
