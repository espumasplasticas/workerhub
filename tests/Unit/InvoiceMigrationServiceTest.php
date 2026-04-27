<?php

namespace Tests\Unit;

use App\Data\Invoices\InvoiceSiesaStateSnapshot;
use App\Data\SiesaWebServiceLogRecord;
use App\Services\Workers\DocumentImportAttemptControlService;
use App\Services\Workers\EpsaSoapConfigurationValidator;
use App\Services\Workers\InvoiceMigrationService;
use App\Services\Workers\Invoices\InvoiceCashNormalizationService;
use App\Services\Workers\Invoices\InvoiceCustomerSyncService;
use App\Services\Workers\Invoices\InvoiceHeaderPreparationService;
use App\Services\Workers\Invoices\InvoiceLegacyStateService;
use App\Services\Workers\Invoices\InvoiceLineFactory;
use App\Services\Workers\Invoices\InvoicePaymentAdjustmentResolver;
use App\Services\Workers\Invoices\InvoicePrototypeRepository;
use App\Services\Workers\Invoices\InvoiceSiesaStateService;
use App\Services\Workers\SiesaImportAuditResult;
use App\Services\Workers\SiesaImportAuditService;
use Epsalibrary\Results\ImportResult;
use Mockery;
use Tests\TestCase;

class InvoiceMigrationServiceTest extends TestCase
{
    public function test_it_imports_an_invoice_after_retrying_internal_balance_adjustments_without_incrementing_the_failure_counter(): void
    {
        $invoiceRecord = (object) [
            'FE_rowid' => 88,
            'FE_TotalNeto' => 120000.0,
        ];
        $header = (object) [
            'F350_ID_CO' => '002',
            'F350_ID_TIPO_DOCTO' => 'F4',
            'F350_CONSEC_DOCTO' => '24787',
            'FE_FormaDePago' => '0',
            'FE_TotalNeto' => 120000.0,
        ];
        $details = [
            (object) ['FD_PrecioUnitario' => 59500, 'FD_ValorNeto' => 59500],
            (object) ['FD_PrecioUnitario' => 60500, 'FD_ValorNeto' => 60500],
        ];
        $payments = [
            (object) ['F_VLR_MEDIO_PAGO' => 120000],
        ];

        $repository = Mockery::mock(InvoicePrototypeRepository::class);
        $repository->shouldReceive('findInvoiceRecord')->once()->andReturn($invoiceRecord);
        $repository->shouldReceive('findHeader')->once()->andReturn($header);
        $repository->shouldReceive('findDetails')->once()->andReturn($details);
        $repository->shouldReceive('findPayments')->once()->andReturn($payments);

        $cashNormalization = Mockery::mock(InvoiceCashNormalizationService::class);
        $cashNormalization->shouldReceive('normalizeIfSupported')->once()->with(Mockery::type('array'), $header)->andReturn(false);

        $headerPreparation = Mockery::mock(InvoiceHeaderPreparationService::class);
        $headerPreparation->shouldReceive('ensureReceivableAndCustomerClassArePresent')->once()->andReturn(false);

        $validator = Mockery::mock(EpsaSoapConfigurationValidator::class);
        $validator->shouldReceive('validate')->once();

        $attemptControl = Mockery::mock(DocumentImportAttemptControlService::class);
        $attemptControl->shouldReceive('registerPreparedInvoiceCustomerAttempts')->once();
        $attemptControl->shouldNotReceive('registerInvoiceMigrationFailureAttemptAndReturnAttemptNumber');

        $customerSync = Mockery::mock(InvoiceCustomerSyncService::class);
        $customerSync->shouldReceive('sync')->twice()->andReturn(
            [
                'status' => 'prepared',
                'line_count' => 12,
                'lines' => array_fill(0, 12, '0200...'),
                'parties' => [
                    ['role' => 'invoice_customer', 'status' => 'prepared', 'line_count' => 12],
                ],
            ],
            [
                'status' => 'skipped',
                'line_count' => 0,
                'lines' => [],
                'parties' => [
                    ['role' => 'invoice_customer', 'status' => 'skipped', 'line_count' => 0],
                ],
            ]
        );

        $paymentAdjustmentResolver = Mockery::mock(InvoicePaymentAdjustmentResolver::class);
        $paymentAdjustmentResolver->shouldReceive('resolveCashPaymentAdjustmentSequence')
            ->once()
            ->with($header, $details, $payments)
            ->andReturn([2.0, 1.0]);

        $lineFactory = Mockery::mock(InvoiceLineFactory::class);
        $lineFactory->shouldReceive('build')
            ->once()
            ->with($header, $details, $payments, 2.0, Mockery::type('array'))
            ->andReturn(array_fill(0, 15, '0461...'));
        $lineFactory->shouldReceive('build')
            ->once()
            ->with($header, $details, $payments, 1.0, Mockery::type('array'))
            ->andReturn(array_fill(0, 3, '0461...'));

        $siesaStateService = Mockery::mock(InvoiceSiesaStateService::class);
        $siesaStateService->shouldReceive('fetch')
            ->times(3)
            ->andReturn(
                new InvoiceSiesaStateSnapshot('002', 'F4', '24787', false),
                new InvoiceSiesaStateSnapshot('002', 'F4', '24787', false),
                new InvoiceSiesaStateSnapshot('002', 'F4', '24787', true, '002', 'F4', '24787', 300, 120000.0, 1)
            );

        $legacyState = Mockery::mock(InvoiceLegacyStateService::class);
        $legacyState->shouldReceive('markMigrationStarted')->once();
        $legacyState->shouldReceive('markMigrated')->once();
        $legacyState->shouldReceive('verificationThreshold')->once()->andReturn(50.0);
        $legacyState->shouldReceive('markVerified')->once();

        $auditService = Mockery::mock(SiesaImportAuditService::class);
        $auditService->shouldReceive('import')
            ->once()
            ->with(
                Mockery::on(static fn (array $lines): bool => count($lines) === 15),
                Mockery::on(static fn (array $context): bool => $context['cash_payment_adjustment'] === 2.0
                    && $context['invoice_adjustment_attempt_number'] === 1
                    && $context['invoice_adjustment_attempts_total'] === 2
                    && $context['customer_sync_line_count'] === 12)
            )
            ->andReturn(new SiesaImportAuditResult(
                new SiesaWebServiceLogRecord(77, '<Envelope />', ['import_stage' => 'invoice_migration']),
                new ImportResult(false, 'Factura rechazada por cuadre', ['saldo' => 'ajuste'], '<Envelope />')
            ));
        $auditService->shouldReceive('import')
            ->once()
            ->with(
                Mockery::on(static fn (array $lines): bool => count($lines) === 3),
                Mockery::on(static fn (array $context): bool => $context['cash_payment_adjustment'] === 1.0
                    && $context['invoice_adjustment_attempt_number'] === 2
                    && $context['invoice_adjustment_attempts_total'] === 2
                    && $context['customer_sync_line_count'] === 0)
            )
            ->andReturn(new SiesaImportAuditResult(
                new SiesaWebServiceLogRecord(78, '<Envelope />', ['import_stage' => 'invoice_migration']),
                new ImportResult(true, 'Factura importada', [], '<Envelope />')
            ));

        $service = new InvoiceMigrationService(
            $auditService,
            $validator,
            $attemptControl,
            $repository,
            $headerPreparation,
            $cashNormalization,
            $customerSync,
            $lineFactory,
            $paymentAdjustmentResolver,
            $siesaStateService,
            $legacyState
        );

        $result = $service->handle([
            'document_id' => '002-F4-24787',
            'source' => 'api',
            'db_connection' => 'sqlsrv',
            'operational_center' => '002',
            'document_type' => 'F4',
            'document_number' => '24787',
        ]);

        $this->assertSame('002-F4-24787', $result['document_id']);
        $this->assertSame('Factura importada', $result['message']);
        $this->assertSame(2, $result['invoice_adjustment_attempt_number']);
        $this->assertSame(2, $result['invoice_adjustment_attempts_total']);
        $this->assertSame(0, $result['invoice_failure_attempt_number']);
        $this->assertSame(1.0, $result['cash_payment_adjustment']);
        $this->assertTrue($result['siesa_state']['exists']);
    }

    public function test_it_registers_a_single_legacy_failure_attempt_after_exhausting_all_internal_balance_adjustments(): void
    {
        $invoiceRecord = (object) [
            'FE_rowid' => 88,
            'FE_TotalNeto' => 120000.0,
        ];
        $header = (object) [
            'F350_ID_CO' => '002',
            'F350_ID_TIPO_DOCTO' => 'F4',
            'F350_CONSEC_DOCTO' => '24787',
            'FE_FormaDePago' => '0',
            'FE_TotalNeto' => 120000.0,
        ];
        $details = [
            (object) ['FD_PrecioUnitario' => 59500, 'FD_ValorNeto' => 59500],
        ];
        $payments = [
            (object) ['F_VLR_MEDIO_PAGO' => 120000],
        ];

        $repository = Mockery::mock(InvoicePrototypeRepository::class);
        $repository->shouldReceive('findInvoiceRecord')->once()->andReturn($invoiceRecord);
        $repository->shouldReceive('findHeader')->once()->andReturn($header);
        $repository->shouldReceive('findDetails')->once()->andReturn($details);
        $repository->shouldReceive('findPayments')->once()->andReturn($payments);

        $cashNormalization = Mockery::mock(InvoiceCashNormalizationService::class);
        $cashNormalization->shouldReceive('normalizeIfSupported')->once()->with(Mockery::type('array'), $header)->andReturn(false);

        $headerPreparation = Mockery::mock(InvoiceHeaderPreparationService::class);
        $headerPreparation->shouldReceive('ensureReceivableAndCustomerClassArePresent')->once()->andReturn(false);

        $validator = Mockery::mock(EpsaSoapConfigurationValidator::class);
        $validator->shouldReceive('validate')->once();

        $attemptControl = Mockery::mock(DocumentImportAttemptControlService::class);
        $attemptControl->shouldReceive('registerPreparedInvoiceCustomerAttempts')->once();
        $attemptControl->shouldReceive('registerInvoiceMigrationFailureAttemptAndReturnAttemptNumber')->once()->andReturn(4);

        $customerSync = Mockery::mock(InvoiceCustomerSyncService::class);
        $customerSync->shouldReceive('sync')->twice()->andReturn([
            'status' => 'prepared',
            'line_count' => 0,
            'lines' => [],
            'parties' => [
                ['role' => 'invoice_customer', 'status' => 'prepared', 'line_count' => 0],
            ],
        ]);

        $paymentAdjustmentResolver = Mockery::mock(InvoicePaymentAdjustmentResolver::class);
        $paymentAdjustmentResolver->shouldReceive('resolveCashPaymentAdjustmentSequence')
            ->once()
            ->with($header, $details, $payments)
            ->andReturn([2.0, 1.0]);

        $lineFactory = Mockery::mock(InvoiceLineFactory::class);
        $lineFactory->shouldReceive('build')
            ->once()
            ->with($header, $details, $payments, 2.0, Mockery::type('array'))
            ->andReturn(array_fill(0, 3, '0461...'));
        $lineFactory->shouldReceive('build')
            ->once()
            ->with($header, $details, $payments, 1.0, Mockery::type('array'))
            ->andReturn(array_fill(0, 3, '0461...'));

        $siesaStateService = Mockery::mock(InvoiceSiesaStateService::class);
        $siesaStateService->shouldReceive('fetch')
            ->times(3)
            ->andReturn(
                new InvoiceSiesaStateSnapshot('002', 'F4', '24787', false),
                new InvoiceSiesaStateSnapshot('002', 'F4', '24787', false),
                new InvoiceSiesaStateSnapshot('002', 'F4', '24787', false)
            );

        $legacyState = Mockery::mock(InvoiceLegacyStateService::class);
        $legacyState->shouldReceive('markMigrationStarted')->once();
        $legacyState->shouldReceive('markMigrationFailed')->once();

        $auditService = Mockery::mock(SiesaImportAuditService::class);
        $auditService->shouldReceive('import')
            ->twice()
            ->andReturn(
                new SiesaImportAuditResult(
                    new SiesaWebServiceLogRecord(77, '<Envelope />', ['import_stage' => 'invoice_migration']),
                    new ImportResult(false, 'Factura rechazada por cuadre', ['saldo' => 'ajuste'], '<Envelope />')
                ),
                new SiesaImportAuditResult(
                    new SiesaWebServiceLogRecord(78, '<Envelope />', ['import_stage' => 'invoice_migration']),
                    new ImportResult(false, 'Factura rechazada por cuadre', ['saldo' => 'ajuste'], '<Envelope />')
                )
            );

        $service = new InvoiceMigrationService(
            $auditService,
            $validator,
            $attemptControl,
            $repository,
            $headerPreparation,
            $cashNormalization,
            $customerSync,
            $lineFactory,
            $paymentAdjustmentResolver,
            $siesaStateService,
            $legacyState
        );

        $this->expectException(\App\Exceptions\WorkerTaskProcessingException::class);
        $this->expectExceptionMessage('Factura rechazada por cuadre');

        $service->handle([
            'document_id' => '002-F4-24787',
            'source' => 'api',
            'db_connection' => 'sqlsrv',
            'operational_center' => '002',
            'document_type' => 'F4',
            'document_number' => '24787',
        ]);
    }

    public function test_it_returns_a_resolved_result_when_the_invoice_already_exists_in_siesa(): void
    {
        $repository = Mockery::mock(InvoicePrototypeRepository::class);
        $repository->shouldReceive('findInvoiceRecord')->once()->andReturn((object) ['FE_rowid' => 88]);
        $repository->shouldReceive('findHeader')->once()->andReturn((object) [
            'F350_ID_CO' => '002',
            'F350_ID_TIPO_DOCTO' => 'F4',
            'F350_CONSEC_DOCTO' => '24787',
        ]);
        $repository->shouldNotReceive('findDetails');
        $repository->shouldNotReceive('findPayments');

        $cashNormalization = Mockery::mock(InvoiceCashNormalizationService::class);
        $cashNormalization->shouldNotReceive('normalizeIfSupported');

        $headerPreparation = Mockery::mock(InvoiceHeaderPreparationService::class);
        $headerPreparation->shouldReceive('ensureReceivableAndCustomerClassArePresent')->once()->andReturn(false);

        $validator = Mockery::mock(EpsaSoapConfigurationValidator::class);
        $validator->shouldNotReceive('validate');

        $attemptControl = Mockery::mock(DocumentImportAttemptControlService::class);
        $attemptControl->shouldNotReceive('registerPreparedInvoiceCustomerAttempts');
        $attemptControl->shouldNotReceive('registerInvoiceMigrationFailureAttemptAndReturnAttemptNumber');

        $customerSync = Mockery::mock(InvoiceCustomerSyncService::class);
        $customerSync->shouldNotReceive('sync');

        $paymentAdjustmentResolver = Mockery::mock(InvoicePaymentAdjustmentResolver::class);
        $paymentAdjustmentResolver->shouldNotReceive('resolveCashPaymentAdjustmentSequence');

        $lineFactory = Mockery::mock(InvoiceLineFactory::class);
        $lineFactory->shouldNotReceive('build');

        $siesaStateService = Mockery::mock(InvoiceSiesaStateService::class);
        $siesaStateService->shouldReceive('fetch')
            ->once()
            ->andReturn(new InvoiceSiesaStateSnapshot('002', 'F4', '24787', true, '002', 'F4', '24787', 300, 120000.0, 1));

        $legacyState = Mockery::mock(InvoiceLegacyStateService::class);
        $legacyState->shouldReceive('markDetectedInSiesa')->once();

        $auditService = Mockery::mock(SiesaImportAuditService::class);
        $auditService->shouldNotReceive('import');

        $service = new InvoiceMigrationService(
            $auditService,
            $validator,
            $attemptControl,
            $repository,
            $headerPreparation,
            $cashNormalization,
            $customerSync,
            $lineFactory,
            $paymentAdjustmentResolver,
            $siesaStateService,
            $legacyState
        );

        $result = $service->handle([
            'document_id' => '002-F4-24787',
            'db_connection' => 'sqlsrv',
            'operational_center' => '002',
            'document_type' => 'F4',
            'document_number' => '24787',
        ]);

        $this->assertTrue($result['already_migrated']);
        $this->assertSame('002-F4-24787', $result['document_id']);
        $this->assertTrue($result['siesa_state']['exists']);
    }
}
