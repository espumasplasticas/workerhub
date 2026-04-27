<?php

namespace App\Services\Workers;

use App\Exceptions\WorkerTaskProcessingException;
use App\Services\Workers\Invoices\InvoiceCashNormalizationService;
use App\Services\Workers\Invoices\InvoiceCustomerSyncService;
use App\Services\Workers\Invoices\InvoiceHeaderPreparationService;
use App\Services\Workers\Invoices\InvoiceLegacyStateService;
use App\Services\Workers\Invoices\InvoiceLineFactory;
use App\Services\Workers\Invoices\InvoicePrototypeRepository;
use App\Services\Workers\Invoices\InvoiceSiesaStateService;
use Throwable;

class InvoiceMigrationService
{
    public function __construct(
        private readonly SiesaImportAuditService $auditService,
        private readonly EpsaSoapConfigurationValidator $soapConfigurationValidator,
        private readonly DocumentImportAttemptControlService $documentImportAttemptControlService,
        private readonly InvoicePrototypeRepository $repository,
        private readonly InvoiceHeaderPreparationService $headerPreparationService,
        private readonly InvoiceCashNormalizationService $cashNormalizationService,
        private readonly InvoiceCustomerSyncService $customerSyncService,
        private readonly InvoiceLineFactory $lineFactory,
        private readonly \App\Services\Workers\Invoices\InvoicePaymentAdjustmentResolver $paymentAdjustmentResolver,
        private readonly InvoiceSiesaStateService $siesaStateService,
        private readonly InvoiceLegacyStateService $legacyStateService
    ) {
    }

    public function handle(array $payload): array
    {
        $timings = [];
        $measure = static function (string $name, callable $callback) use (&$timings) {
            $startedAt = microtime(true);
            $result = $callback();
            $timings[$name] = round((microtime(true) - $startedAt) * 1000, 2);

            return $result;
        };

        $invoiceRecord = $measure('find_invoice_record', fn () => $this->repository->findInvoiceRecord($payload));

        if ($measure('ensure_legacy_receivable_and_customer_class', fn () => $this->headerPreparationService->ensureReceivableAndCustomerClassArePresent($payload, $invoiceRecord))) {
            $invoiceRecord = $measure('find_invoice_record_after_header_preparation', fn () => $this->repository->findInvoiceRecord($payload));
        }

        $header = $measure('find_header', fn () => $this->repository->findHeader($payload));
        $siesaStateBefore = $measure('fetch_siesa_state_before', fn () => $this->siesaStateService->fetch($payload, $header));

        if ($siesaStateBefore->exists) {
            $measure('mark_detected_in_siesa', fn () => $this->legacyStateService->markDetectedInSiesa($payload, $siesaStateBefore));

            return [
                'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
                'message' => 'La factura ya existe en Siesa. Se sincronizaron indicadores legacy y se omite la retransmision.',
                'errors' => [],
                'invoice_reference' => $this->buildReference($payload),
                'invoice_record' => ['row_id' => $invoiceRecord->FE_rowid ?? null],
                'siesa_state' => $siesaStateBefore->toArray(),
                'already_migrated' => true,
                'timings_ms' => $timings,
            ];
        }

        $measure('validate_soap_configuration', fn () => $this->soapConfigurationValidator->validate());
        $measure('mark_migration_started', fn () => $this->legacyStateService->markMigrationStarted($payload));
        $importSucceeded = false;

        try {
            if ($measure('normalize_credit_invoice_to_cash_if_supported', fn () => $this->cashNormalizationService->normalizeIfSupported($payload, $header))) {
                $invoiceRecord = $measure('find_invoice_record_after_cash_normalization', fn () => $this->repository->findInvoiceRecord($payload));
                $header = $measure('find_header_after_cash_normalization', fn () => $this->repository->findHeader($payload));
            }

            $customerSync = $measure('customer_sync', fn () => $this->customerSyncService->sync($payload, $header));
            $details = $measure('find_details', fn () => $this->repository->findDetails($payload));
            $payments = $measure('find_payments', fn () => $this->repository->findPayments($payload));
            $attemptNumber = $measure('register_invoice_import_attempt_control', function () use ($payload, $customerSync): int {
                $this->documentImportAttemptControlService->registerPreparedInvoiceCustomerAttempts($payload, $customerSync);

                return $this->documentImportAttemptControlService->registerInvoiceMigrationAttemptAndReturnAttemptNumber($payload);
            });
            $cashPaymentAdjustment = $measure('resolve_cash_payment_adjustment', fn () => $this->paymentAdjustmentResolver->resolveCashPaymentAdjustment(
                $header,
                $details,
                $payments,
                $attemptNumber
            ));
            $lines = $measure('build_invoice_lines', fn () => $this->lineFactory->build(
                $header,
                $details,
                $payments,
                $cashPaymentAdjustment,
                (array) ($customerSync['lines'] ?? [])
            ));
            $audit = $measure('audit_import', fn () => $this->auditService->import($lines, [
                'worker_task_id' => $payload['_workerhub_task_id'] ?? null,
                'task_type' => $payload['_workerhub_task_type'] ?? 'invoice_migration',
                'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
                'source' => $payload['source'] ?? null,
                'import_stage' => 'invoice_migration',
                'line_count' => count($lines),
                'detail_count' => count($details),
                'payment_count' => count($payments),
                'customer_sync_line_count' => (int) ($customerSync['line_count'] ?? 0),
                'customer_sync' => $customerSync,
                'import_attempt_number' => $attemptNumber,
                'cash_payment_adjustment' => $cashPaymentAdjustment,
            ]));

            if (!$audit->result->success) {
                $siesaStateAfterFailedImport = $measure('fetch_siesa_state_after_failed_import', fn () => $this->siesaStateService->fetch($payload, $header));

                if ($siesaStateAfterFailedImport->exists) {
                    $measure('mark_detected_in_siesa_after_failed_import', fn () => $this->legacyStateService->markDetectedInSiesa($payload, $siesaStateAfterFailedImport));

                    return [
                        'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
                        'message' => 'Siesa reporto error, pero la factura quedo registrada. Se sincronizaron indicadores legacy y se omite el retry.',
                        'errors' => $audit->result->errors,
                        'invoice_reference' => $this->buildReference($payload),
                        'invoice_record' => ['row_id' => $invoiceRecord->FE_rowid ?? null],
                        'siesa_state' => $siesaStateAfterFailedImport->toArray(),
                        'already_migrated' => true,
                        'siesa_web_service' => $audit->log->toArray(),
                        'timings_ms' => $timings,
                    ];
                }

                throw new WorkerTaskProcessingException(
                    $audit->result->message,
                    [
                        'errors' => $audit->result->errors,
                        'payload' => $payload,
                        'siesa_web_service' => $audit->log->toArray(),
                    ]
                );
            }

            $measure('mark_migrated', fn () => $this->legacyStateService->markMigrated($payload));
            $importSucceeded = true;
            $siesaStateAfter = $measure('fetch_siesa_state_after', fn () => $this->siesaStateService->fetch($payload, $header));

            if ($siesaStateAfter->exists) {
                $legacyInvoiceTotal = (float) ($invoiceRecord->FE_TotalNeto ?? $header->FE_TotalNeto ?? 0);
                $enterpriseInvoiceTotal = (float) ($siesaStateAfter->netTotal ?? 0);
                $difference = abs($legacyInvoiceTotal - $enterpriseInvoiceTotal);

                if ($legacyInvoiceTotal > 0 && $enterpriseInvoiceTotal > 0 && $difference <= $this->legacyStateService->verificationThreshold()) {
                    $measure('mark_verified', fn () => $this->legacyStateService->markVerified($payload, $siesaStateAfter, $legacyInvoiceTotal));
                }
            }

            return [
                'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
                'message' => $audit->result->message,
                'errors' => $audit->result->errors,
                'line_count' => count($lines),
                'detail_count' => count($details),
                'payment_count' => count($payments),
                'customer_sync_line_count' => (int) ($customerSync['line_count'] ?? 0),
                'invoice_reference' => $this->buildReference($payload),
                'invoice_record' => ['row_id' => $invoiceRecord->FE_rowid ?? null],
                'siesa_web_service' => $audit->log->toArray(),
                'siesa_state' => $siesaStateAfter->toArray(),
                'import_attempt_number' => $attemptNumber,
                'cash_payment_adjustment' => $cashPaymentAdjustment,
                'timings_ms' => $timings,
            ];
        } catch (Throwable $exception) {
            if (!$importSucceeded) {
                $measure('mark_migration_failed', fn () => $this->legacyStateService->markMigrationFailed($payload));
            }

            throw $exception;
        }
    }

    private function buildReference(array $payload): string
    {
        return implode('-', array_filter([
            trim((string) ($payload['operational_center'] ?? '')),
            trim((string) ($payload['document_type'] ?? '')),
            trim((string) ($payload['document_number'] ?? '')),
        ], static fn (string $value): bool => $value !== ''));
    }
}
