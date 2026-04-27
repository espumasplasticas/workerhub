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

            $initialCustomerSync = $measure('customer_sync', fn () => $this->customerSyncService->sync($payload, $header));
            $details = $measure('find_details', fn () => $this->repository->findDetails($payload));
            $payments = $measure('find_payments', fn () => $this->repository->findPayments($payload));
            $measure('register_invoice_customer_import_attempt_control', function () use ($payload, $initialCustomerSync): void {
                $this->documentImportAttemptControlService->registerPreparedInvoiceCustomerAttempts($payload, $initialCustomerSync);
            });
            $cashPaymentAdjustments = $measure('resolve_cash_payment_adjustment_sequence', fn () => $this->paymentAdjustmentResolver->resolveCashPaymentAdjustmentSequence(
                $header,
                $details,
                $payments
            ));
            $internalAttemptCount = count($cashPaymentAdjustments);
            $lastAudit = null;
            $selectedCashPaymentAdjustment = 0.0;
            $selectedInternalAttemptNumber = 0;

            foreach ($cashPaymentAdjustments as $internalAttemptIndex => $cashPaymentAdjustment) {
                $currentCustomerSync = $internalAttemptIndex === 0
                    ? $initialCustomerSync
                    : $this->customerSyncService->sync($payload, $header);

                $lines = $this->lineFactory->build(
                    $header,
                    $details,
                    $payments,
                    $cashPaymentAdjustment,
                    (array) ($currentCustomerSync['lines'] ?? [])
                );
                $audit = $this->auditService->import($lines, [
                    'worker_task_id' => $payload['_workerhub_task_id'] ?? null,
                    'task_type' => $payload['_workerhub_task_type'] ?? 'invoice_migration',
                    'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
                    'source' => $payload['source'] ?? null,
                    'import_stage' => 'invoice_migration',
                    'line_count' => count($lines),
                    'detail_count' => count($details),
                    'payment_count' => count($payments),
                    'customer_sync_line_count' => (int) ($currentCustomerSync['line_count'] ?? 0),
                    'customer_sync' => $currentCustomerSync,
                    'cash_payment_adjustment' => $cashPaymentAdjustment,
                    'invoice_adjustment_attempt_number' => $internalAttemptIndex + 1,
                    'invoice_adjustment_attempts_total' => $internalAttemptCount,
                ]);
                $lastAudit = $audit;
                $selectedCashPaymentAdjustment = $cashPaymentAdjustment;
                $selectedInternalAttemptNumber = $internalAttemptIndex + 1;

                if ($audit->result->success) {
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
                        'customer_sync_line_count' => (int) ($currentCustomerSync['line_count'] ?? 0),
                        'invoice_reference' => $this->buildReference($payload),
                        'invoice_record' => ['row_id' => $invoiceRecord->FE_rowid ?? null],
                        'siesa_web_service' => $audit->log->toArray(),
                        'siesa_state' => $siesaStateAfter->toArray(),
                        'cash_payment_adjustment' => $cashPaymentAdjustment,
                        'invoice_adjustment_attempt_number' => $internalAttemptIndex + 1,
                        'invoice_adjustment_attempts_total' => $internalAttemptCount,
                        'invoice_failure_attempt_number' => 0,
                        'timings_ms' => $timings,
                    ];
                }

                $siesaStateAfterFailedImport = $this->siesaStateService->fetch($payload, $header);

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
                        'cash_payment_adjustment' => $cashPaymentAdjustment,
                        'invoice_adjustment_attempt_number' => $internalAttemptIndex + 1,
                        'invoice_adjustment_attempts_total' => $internalAttemptCount,
                        'invoice_failure_attempt_number' => 0,
                        'timings_ms' => $timings,
                    ];
                }
            }

            $failedAttemptNumber = $measure('register_invoice_failure_attempt_control', fn () => $this->documentImportAttemptControlService->registerInvoiceMigrationFailureAttemptAndReturnAttemptNumber($payload));

            throw new WorkerTaskProcessingException(
                $lastAudit?->result->message ?? 'No fue posible migrar la factura luego de recorrer todos los ajustes legacy disponibles.',
                [
                    'errors' => $lastAudit?->result->errors ?? [],
                    'payload' => $payload,
                    'siesa_web_service' => $lastAudit?->log->toArray(),
                    'cash_payment_adjustment' => $selectedCashPaymentAdjustment,
                    'invoice_adjustment_attempt_number' => $selectedInternalAttemptNumber,
                    'invoice_adjustment_attempts_total' => $internalAttemptCount,
                    'invoice_failure_attempt_number' => $failedAttemptNumber,
                ]
            );
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
