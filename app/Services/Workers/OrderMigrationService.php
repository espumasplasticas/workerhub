<?php

namespace App\Services\Workers;

use App\Exceptions\WorkerTaskProcessingException;
use App\Services\Workers\Orders\OrderCustomerSyncService;
use App\Services\Workers\Orders\OrderCashConversionService;
use App\Services\Workers\Orders\OrderLegacyStateService;
use App\Services\Workers\Orders\OrderLineFactory;
use App\Services\Workers\Orders\OrderPreMigrationGuard;
use App\Services\Workers\Orders\OrderPrototypeRepository;
use App\Services\Workers\Orders\OrderSiesaStateService;
use Throwable;

class OrderMigrationService
{
    public function __construct(
        private readonly SiesaImportAuditService $auditService,
        private readonly EpsaSoapConfigurationValidator $soapConfigurationValidator,
        private readonly OrderPreMigrationGuard $preMigrationGuard,
        private readonly OrderPrototypeRepository $repository,
        private readonly OrderCashConversionService $cashConversionService,
        private readonly OrderCustomerSyncService $customerSyncService,
        private readonly OrderLineFactory $lineFactory,
        private readonly OrderSiesaStateService $siesaStateService,
        private readonly OrderLegacyStateService $legacyStateService
    ) {
    }

    public function handle(array $payload): array
    {
        $orderRecord = $this->repository->findOrderRecord($payload);
        $preMigrationSnapshot = $this->preMigrationGuard->assertCanMigrate($payload, $orderRecord);
        $header = $this->repository->findHeader($payload);

        if ($this->cashConversionService->normalizeIfSupported($payload, $header)) {
            $header = $this->repository->findHeader($payload);
        }

        $siesaStateBefore = $this->siesaStateService->fetch($payload, $header);

        if ($siesaStateBefore->exists) {
            $this->legacyStateService->markDetectedInSiesa($payload, $siesaStateBefore);

            throw new WorkerTaskProcessingException(
                'El pedido ya existe en Siesa y no debe retransmitirse.',
                [
                    'payload' => $payload,
                    'siesa_state' => $siesaStateBefore->toArray(),
                ]
            );
        }

        $this->soapConfigurationValidator->validate();
            $this->legacyStateService->markMigrationStarted($payload);
            $importSucceeded = false;
            $legacyNetTotal = null;

        try {
            $customerSync = $this->customerSyncService->sync($payload, $header);
            $details = $this->repository->findDetails($payload);
            $orderLines = $this->lineFactory->build($payload, $header, $orderRecord, $details);
            $lines = array_merge($customerSync['lines'] ?? [], $orderLines);
            $audit = $this->auditService->import($lines, [
                'worker_task_id' => $payload['_workerhub_task_id'] ?? null,
                'task_type' => $payload['_workerhub_task_type'] ?? 'order_migration',
                'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
                'source' => $payload['source'] ?? null,
                'import_stage' => 'order_migration',
                'line_count' => count($lines),
                'order_line_count' => count($orderLines),
                'customer_sync_line_count' => (int) ($customerSync['line_count'] ?? 0),
                'detail_count' => count($details),
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
                $this->legacyStateService->updateEnterpriseRowId($payload, $siesaStateAfter);

                $legacyNetTotal = $this->legacyStateService->computeLegacyNetTotal($payload);
                $enterpriseNetTotal = (float) ($siesaStateAfter->netTotal ?? 0);
                $difference = abs($enterpriseNetTotal - $legacyNetTotal);
                $isGift = (int) ($header->PE_IndicadorObsequio ?? 0) === 1;

                if ($difference < $this->legacyStateService->verificationThreshold() || $isGift) {
                    $this->legacyStateService->markVerified($payload, $siesaStateAfter, $legacyNetTotal);
                }
            }

            return [
                'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
                'source' => $payload['source'] ?? null,
                'message' => $result->message,
                'errors' => $result->errors,
                'siesa_web_service' => $audit->log->toArray(),
                'import_payload' => $result->payload,
                'line_count' => count($lines),
                'order_line_count' => count($orderLines),
                'customer_sync_line_count' => (int) ($customerSync['line_count'] ?? 0),
                'detail_count' => count($details),
                'order_reference' => $this->buildReference($payload),
                'pre_migration' => $preMigrationSnapshot,
                'customer_sync' => $customerSync,
                'siesa_state' => $siesaStateAfter->toArray(),
                'legacy_net_total' => $legacyNetTotal,
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
