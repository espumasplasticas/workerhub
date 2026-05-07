<?php

namespace App\Services\Workers;

use App\Exceptions\WorkerTaskProcessingException;
use App\Services\Workers\Receipts\ReceiptCrossReferenceGuard;
use App\Services\Workers\Receipts\ReceiptCustomerSyncService;
use App\Services\Workers\Receipts\ReceiptLegacyStateService;
use App\Services\Workers\Receipts\ReceiptLineFactory;
use App\Services\Workers\Receipts\ReceiptPreMigrationGuard;
use App\Services\Workers\Receipts\ReceiptPrototypeRepository;
use App\Services\Workers\Receipts\ReceiptSiesaStateService;
use Throwable;

class ReceiptMigrationService
{
    public function __construct(
        private readonly SiesaImportAuditService $auditService,
        private readonly EpsaSoapConfigurationValidator $soapConfigurationValidator,
        private readonly DocumentImportAttemptControlService $documentImportAttemptControlService,
        private readonly ReceiptPreMigrationGuard $preMigrationGuard,
        private readonly ReceiptPrototypeRepository $repository,
        private readonly ReceiptCrossReferenceGuard $crossReferenceGuard,
        private readonly ReceiptCustomerSyncService $customerSyncService,
        private readonly ReceiptLineFactory $lineFactory,
        private readonly ReceiptSiesaStateService $siesaStateService,
        private readonly ReceiptLegacyStateService $legacyStateService
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

        $preMigrationSnapshot = $measure('pre_migration_guard', fn () => $this->preMigrationGuard->assertCanMigrate($payload));

        if ($preMigrationSnapshot->isLegacyExportVerified) {
            return $this->alreadyMigratedResult(
                payload: $payload,
                preMigrationSnapshot: $preMigrationSnapshot,
                siesaState: null,
                timings: $timings,
                reason: 'receipt_legacy_export_verified'
            );
        }

        $header = $measure('find_header', fn () => $this->repository->findHeader($payload));
        $siesaStateBefore = $measure('fetch_siesa_state_before', fn () => $this->siesaStateService->fetch($payload, $header));

        if ($siesaStateBefore->exists) {
            $measure('mark_detected_in_siesa', fn () => $this->legacyStateService->markDetectedInSiesa($payload));

            return $this->alreadyMigratedResult(
                payload: $payload,
                preMigrationSnapshot: $preMigrationSnapshot,
                siesaState: $siesaStateBefore,
                timings: $timings,
                reason: 'receipt_already_exists_in_siesa'
            );
        }

        $measure('validate_soap_configuration', fn () => $this->soapConfigurationValidator->validate());
        $measure('mark_migration_started', fn () => $this->legacyStateService->markMigrationStarted($payload));
        $importSucceeded = false;

        try {
            $crossReference = $measure('cross_reference_guard', fn () => $this->crossReferenceGuard->assertExists($payload, $header));
            $customerSync = $measure('customer_sync', fn () => $this->customerSyncService->sync($payload, $header));
            $payments = $measure('find_payments', fn () => $this->repository->findPayments($payload));
            $receiptLines = $measure('build_receipt_lines', fn () => $this->lineFactory->build($header, $payments));
            $lines = array_merge($customerSync['lines'] ?? [], $receiptLines);
            $measure('register_import_attempt_control', function () use ($payload, $customerSync): void {
                $this->documentImportAttemptControlService->registerPreparedReceiptCustomerAttempts($payload, $customerSync);
                $this->documentImportAttemptControlService->registerReceiptMigrationAttempt($payload);
            });
            $audit = $measure('audit_import', fn () => $this->auditService->import($lines, [
                'worker_task_id' => $payload['_workerhub_task_id'] ?? null,
                'task_type' => $payload['_workerhub_task_type'] ?? 'receipt_migration',
                'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
                'source' => $payload['source'] ?? null,
                'import_stage' => 'receipt_migration',
                'line_count' => count($lines),
                'receipt_line_count' => count($receiptLines),
                'customer_sync_line_count' => (int) ($customerSync['line_count'] ?? 0),
                'payment_count' => count($payments),
                'customer_sync' => $customerSync,
            ]));
            $result = $audit->result;

            if (!$result->success) {
                throw new WorkerTaskProcessingException(
                    $result->message,
                    [
                        'errors' => $result->errors,
                        'payload' => $payload,
                        'siesa_web_service' => $audit->log->toArray(),
                        'xml_payload' => $result->payload,
                    ]
                );
            }

            $measure('mark_migrated', fn () => $this->legacyStateService->markMigrated($payload));
            $importSucceeded = true;
            $siesaStateAfter = $measure('fetch_siesa_state_after', fn () => $this->siesaStateService->fetch($payload, $header));

            if ($siesaStateAfter->exists) {
                $measure('mark_detected_in_siesa_after_import', fn () => $this->legacyStateService->markDetectedInSiesa($payload));
            }

            return [
                'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
                'source' => $payload['source'] ?? null,
                'message' => $result->message,
                'errors' => $result->errors,
                'siesa_web_service' => $audit->log->toArray(),
                'import_payload' => $result->payload,
                'line_count' => count($lines),
                'receipt_line_count' => count($receiptLines),
                'customer_sync_line_count' => (int) ($customerSync['line_count'] ?? 0),
                'payment_count' => count($payments),
                'receipt_reference' => $this->buildReference($payload),
                'pre_migration' => $preMigrationSnapshot->toArray(),
                'cross_reference' => $crossReference->toArray(),
                'customer_sync' => $customerSync,
                'siesa_state' => $siesaStateAfter->toArray(),
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

    private function alreadyMigratedResult(
        array $payload,
        \App\Data\Receipts\ReceiptPreMigrationSnapshot $preMigrationSnapshot,
        ?\App\Data\Receipts\ReceiptSiesaStateSnapshot $siesaState,
        array $timings,
        string $reason
    ): array {
        return [
            'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
            'source' => $payload['source'] ?? null,
            'message' => 'El recibo ya fue migrado/verificado. Se omite la retransmision.',
            'errors' => [],
            'siesa_web_service' => null,
            'import_payload' => null,
            'line_count' => 0,
            'receipt_line_count' => 0,
            'customer_sync_line_count' => 0,
            'payment_count' => 0,
            'receipt_reference' => $this->buildReference($payload),
            'pre_migration' => $preMigrationSnapshot->toArray(),
            'cross_reference' => null,
            'customer_sync' => [
                'status' => 'skipped',
                'line_count' => 0,
                'lines' => [],
                'reason' => $reason,
            ],
            'siesa_state' => $siesaState?->toArray(),
            'already_migrated' => true,
            'timings_ms' => $timings,
        ];
    }
}
