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
        $preMigrationSnapshot = $this->preMigrationGuard->assertCanMigrate($payload);
        $header = $this->repository->findHeader($payload);
        $siesaStateBefore = $this->siesaStateService->fetch($payload, $header);

        if ($siesaStateBefore->exists) {
            $this->legacyStateService->markDetectedInSiesa($payload);

            throw new WorkerTaskProcessingException(
                'El recibo ya existe en Siesa y no debe retransmitirse.',
                [
                    'payload' => $payload,
                    'siesa_state' => $siesaStateBefore->toArray(),
                ]
            );
        }

        $this->soapConfigurationValidator->validate();
        $this->legacyStateService->markMigrationStarted($payload);
        $importSucceeded = false;

        try {
            $crossReference = $this->crossReferenceGuard->assertExists($payload, $header);
            $customerSync = $this->customerSyncService->sync($payload, $header);
            $payments = $this->repository->findPayments($payload);
            $receiptLines = $this->lineFactory->build($header, $payments);
            $lines = array_merge($customerSync['lines'] ?? [], $receiptLines);
            $audit = $this->auditService->import($lines, [
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
            ]);
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

            $this->legacyStateService->markMigrated($payload);
            $importSucceeded = true;
            $siesaStateAfter = $this->siesaStateService->fetch($payload, $header);

            if ($siesaStateAfter->exists) {
                $this->legacyStateService->markDetectedInSiesa($payload);
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
            ];
        } catch (Throwable $exception) {
            if (!$importSucceeded) {
                $this->legacyStateService->markMigrationFailed($payload);
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
