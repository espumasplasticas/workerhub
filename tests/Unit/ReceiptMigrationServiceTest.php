<?php

namespace Tests\Unit;

use App\Data\Receipts\ReceiptPreMigrationSnapshot;
use App\Exceptions\WorkerTaskProcessingException;
use App\Data\Receipts\ReceiptCrossReferenceSnapshot;
use App\Services\Workers\EpsaSoapConfigurationValidator;
use App\Services\Workers\ReceiptMigrationService;
use App\Services\Workers\Receipts\ReceiptCrossReferenceGuard;
use App\Services\Workers\Receipts\ReceiptCustomerSyncService;
use App\Services\Workers\Receipts\ReceiptLineFactory;
use App\Services\Workers\Receipts\ReceiptPreMigrationGuard;
use App\Services\Workers\Receipts\ReceiptPrototypeRepository;
use Epsalibrary\Contracts\ImportManagerInterface;
use Epsalibrary\Results\ImportResult;
use Mockery;
use stdClass;
use Tests\TestCase;

class ReceiptMigrationServiceTest extends TestCase
{
    public function test_it_imports_a_receipt_using_prototype_views_and_epsa_library(): void
    {
        $header = (object) [
            'F350_ID_CO' => '001',
            'F350_ID_TIPO_DOCTO' => 'RX',
            'F350_CONSEC_DOCTO' => '1001',
            'F350_FECHA' => '20260414',
            'F357_ID_CAJA' => '001',
            'F357_FECHA_RECAUDO' => '20260414',
            'F350_ID_TERCERO' => '900123',
            'F357_ID_MONEDA_INGRESO' => 'COP',
            'F357_VALOR_INGRESO' => 100000,
            'F357_ID_MONEDA_APLICAR' => 'COP',
            'F357_VALOR_APLICAR_REAL' => 100000,
            'F357_ID_COBRADOR' => 'A001',
            'F357_ID_UN' => '02',
            'F357_ID_CCOSTO' => 'CC-001',
            'F357_ID_FE' => 'FE-RECIBOS',
            'F350_ID_CLASE_DOCTO' => '30',
            'F350_IND_ESTADO' => '1',
            'F350_IND_IMPRESION' => '0',
            'F350_NOTAS' => 'Recibo de prueba',
            'F351_ID_AUXILIAR_AJUSTE' => '',
            'F351_ID_CCOSTO_AJUSTE' => '',
            'F351_ID_AUXILIAR_PP' => '',
            'F351_ID_CCOSTO_PP' => '',
            'F351_ID_AUXILIAR_OTRO_ING' => '',
            'F351_ID_TERCERO_OTRO_ING' => '',
            'F351_ID_SUCURSAL_OTRO_ING' => '000',
            'F351_ID_CO_OTRO_ING' => '000',
            'F351_ID_UN_OTRO_ING' => '',
            'F351_ID_CCOSTO_OTRO_ING' => '',
            'F357_REFERENCIA' => '001-RX-1001',
            'F353_ID_SUCURSAL_DOCTO_CRUCE' => '001',
        ];

        $payment = (object) [
            'F350_ID_CO' => '001',
            'F350_ID_TIPO_DOCTO' => 'RX',
            'F350_CONSEC_DOCTO' => '1001',
            'F358_ID_MEDIOS_PAGO' => 'EFE',
            'F358_VALOR' => 100000,
            'F358_ID_BANCO' => '',
            'F358_NRO_CHEQUE' => '',
            'F358_NRO_CUENTA' => '',
            'F358_COD_SEGURIDAD' => '',
            'F358_NRO_AUTORIZACION' => '',
            'F358_FECHA_VCTO' => '',
            'F358_REFERENCIA_OTROS' => '',
            'F358_FECHA_CONSIGNACION' => '',
            'F358_ID_CAUSALES_DEVOLUCION' => '',
            'F358_ID_TERCERO' => '900123',
            'F358_NOTAS' => 'Pago efectivo',
            'F358_ID_CCOSTO' => 'CC-001',
        ];

        $repository = Mockery::mock(ReceiptPrototypeRepository::class);
        $repository->shouldReceive('findHeader')->once()->andReturn($header);
        $repository->shouldReceive('findPayments')->once()->andReturn([$payment]);

        $validator = Mockery::mock(EpsaSoapConfigurationValidator::class);
        $validator->shouldReceive('validate')->once();

        $guard = Mockery::mock(ReceiptPreMigrationGuard::class);
        $guard->shouldReceive('assertCanMigrate')
            ->once()
            ->andReturn(new ReceiptPreMigrationSnapshot(
                operationalCenter: '001',
                documentType: 'RX',
                documentNumber: '1001',
                totalAmount: 100000,
                legalizedAmount: 100000,
                isCancelled: false,
                isCancellationRequested: false,
                isWompiExpiredWithoutPayment: false,
            ));

        $customerSync = Mockery::mock(ReceiptCustomerSyncService::class);
        $customerSync->shouldReceive('sync')
            ->once()
            ->andReturn([
                'status' => 'synced',
                'line_count' => 12,
            ]);

        $crossReferenceGuard = Mockery::mock(ReceiptCrossReferenceGuard::class);
        $crossReferenceGuard->shouldReceive('assertExists')
            ->once()
            ->andReturn(new ReceiptCrossReferenceSnapshot(
                auxiliaryId: '28050505',
                operationalCenter: '001',
                unit: '02',
                branch: '001',
                documentType: 'RX',
                documentNumber: '1001',
                exists: true,
            ));

        $lineFactory = new ReceiptLineFactory();

        $importManager = Mockery::mock(ImportManagerInterface::class);
        $importManager->shouldReceive('import')
            ->once()
            ->with(Mockery::on(static function (array $lines): bool {
                return count($lines) === 3
                    && str_starts_with($lines[0], '035700')
                    && str_starts_with($lines[1], '035701')
                    && str_starts_with($lines[2], '035702');
            }))
            ->andReturn(new ImportResult(true, 'Recibo importado', [], '<Envelope />'));

        $service = new ReceiptMigrationService(
            $importManager,
            $validator,
            $guard,
            $repository,
            $crossReferenceGuard,
            $customerSync,
            $lineFactory
        );

        $result = $service->handle([
            'document_id' => '001-RX-1001',
            'source' => 'api',
            'db_connection' => 'sqlsrv',
            'operational_center' => '001',
            'document_type' => 'RX',
            'document_number' => '1001',
        ]);

        $this->assertSame('001-RX-1001', $result['document_id']);
        $this->assertSame('Recibo importado', $result['message']);
        $this->assertSame(3, $result['line_count']);
        $this->assertSame(1, $result['payment_count']);
        $this->assertSame(100000.0, $result['pre_migration']['legalized_amount']);
        $this->assertTrue($result['cross_reference']['exists']);
        $this->assertSame('synced', $result['customer_sync']['status']);
    }

    public function test_it_surfaces_receipt_import_failures_with_context(): void
    {
        $repository = Mockery::mock(ReceiptPrototypeRepository::class);
        $repository->shouldReceive('findHeader')->once()->andReturn(new stdClass());
        $repository->shouldReceive('findPayments')->once()->andReturn([new stdClass()]);

        $validator = Mockery::mock(EpsaSoapConfigurationValidator::class);
        $validator->shouldReceive('validate')->once();

        $guard = Mockery::mock(ReceiptPreMigrationGuard::class);
        $guard->shouldReceive('assertCanMigrate')
            ->once()
            ->andReturn(new ReceiptPreMigrationSnapshot(
                operationalCenter: '001',
                documentType: 'RX',
                documentNumber: '1001',
                totalAmount: 100000,
                legalizedAmount: 100000,
                isCancelled: false,
                isCancellationRequested: false,
                isWompiExpiredWithoutPayment: false,
            ));

        $customerSync = Mockery::mock(ReceiptCustomerSyncService::class);
        $customerSync->shouldReceive('sync')
            ->once()
            ->andReturn([
                'status' => 'skipped',
                'line_count' => 0,
            ]);

        $crossReferenceGuard = Mockery::mock(ReceiptCrossReferenceGuard::class);
        $crossReferenceGuard->shouldReceive('assertExists')
            ->once()
            ->andReturn(new ReceiptCrossReferenceSnapshot(
                auxiliaryId: '28050505',
                operationalCenter: '001',
                unit: '02',
                branch: '001',
                documentType: 'RX',
                documentNumber: '1001',
                exists: true,
            ));

        $lineFactory = Mockery::mock(ReceiptLineFactory::class);
        $lineFactory->shouldReceive('build')->once()->andReturn(['035700...', '035701...', '035702...']);

        $importManager = Mockery::mock(ImportManagerInterface::class);
        $importManager->shouldReceive('import')
            ->once()
            ->andReturn(new ImportResult(false, 'Fallo importando recibo', ['soap_error'], '<Envelope />'));

        $service = new ReceiptMigrationService(
            $importManager,
            $validator,
            $guard,
            $repository,
            $crossReferenceGuard,
            $customerSync,
            $lineFactory
        );

        $this->expectException(WorkerTaskProcessingException::class);
        $this->expectExceptionMessage('Fallo importando recibo');

        $service->handle([
            'document_id' => '001-RX-1001',
            'db_connection' => 'sqlsrv',
            'operational_center' => '001',
            'document_type' => 'RX',
            'document_number' => '1001',
        ]);
    }
}
