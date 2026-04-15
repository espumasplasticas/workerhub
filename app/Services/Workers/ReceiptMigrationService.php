<?php

namespace App\Services\Workers;

use App\Exceptions\WorkerTaskProcessingException;
use App\Services\Workers\Receipts\ReceiptCrossReferenceGuard;
use App\Services\Workers\Receipts\ReceiptCustomerSyncService;
use App\Services\Workers\Receipts\ReceiptLineFactory;
use App\Services\Workers\Receipts\ReceiptPreMigrationGuard;
use App\Services\Workers\Receipts\ReceiptPrototypeRepository;
class ReceiptMigrationService
{
    public function __construct(
        private readonly SiesaImportAuditService $auditService,
        private readonly EpsaSoapConfigurationValidator $soapConfigurationValidator,
        private readonly ReceiptPreMigrationGuard $preMigrationGuard,
        private readonly ReceiptPrototypeRepository $repository,
        private readonly ReceiptCrossReferenceGuard $crossReferenceGuard,
        private readonly ReceiptCustomerSyncService $customerSyncService,
        private readonly ReceiptLineFactory $lineFactory
    ) {
    }

    public function handle(array $payload): array
    {
        $preMigrationSnapshot = $this->preMigrationGuard->assertCanMigrate($payload);
        $header = $this->repository->findHeader($payload);

        $this->soapConfigurationValidator->validate();

        $crossReference = $this->crossReferenceGuard->assertExists($payload, $header);
        $customerSync = $this->customerSyncService->sync($payload, $header);
        $payments = $this->repository->findPayments($payload);
        $lines = $this->lineFactory->build($header, $payments);
        $audit = $this->auditService->import($lines, [
            'worker_task_id' => $payload['_workerhub_task_id'] ?? null,
            'task_type' => $payload['_workerhub_task_type'] ?? 'receipt_migration',
            'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
            'source' => $payload['source'] ?? null,
            'import_stage' => 'receipt_migration',
            'line_count' => count($lines),
            'payment_count' => count($payments),
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

        return [
            'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
            'source' => $payload['source'] ?? null,
            'message' => $result->message,
            'errors' => $result->errors,
            'siesa_web_service' => $audit->log->toArray(),
            'import_payload' => $result->payload,
            'line_count' => count($lines),
            'payment_count' => count($payments),
            'receipt_reference' => $this->buildReference($payload),
            'pre_migration' => $preMigrationSnapshot->toArray(),
            'cross_reference' => $crossReference->toArray(),
            'customer_sync' => $customerSync,
        ];
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
