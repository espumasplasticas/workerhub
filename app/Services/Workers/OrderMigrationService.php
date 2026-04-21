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
        $timings = [];
        $measure = static function (string $name, callable $callback) use (&$timings) {
            $startedAt = microtime(true);
            $result = $callback();
            $timings[$name] = round((microtime(true) - $startedAt) * 1000, 2);

            return $result;
        };

        $orderRecord = $measure('find_order_record', fn () => $this->repository->findOrderRecord($payload));
        $preMigrationSnapshot = $measure('pre_migration_guard', fn () => $this->preMigrationGuard->assertCanMigrate($payload, $orderRecord));
        $header = $measure('find_header', fn () => $this->repository->findHeader($payload));

        if ($measure('cash_conversion_normalize', fn () => $this->cashConversionService->normalizeIfSupported($payload, $header))) {
            $header = $measure('find_header_after_cash_conversion', fn () => $this->repository->findHeader($payload));
        }

        $siesaStateBefore = $measure('fetch_siesa_state_before', fn () => $this->siesaStateService->fetch($payload, $header));

        if ($siesaStateBefore->exists) {
            $measure('mark_detected_in_siesa', fn () => $this->legacyStateService->markDetectedInSiesa($payload, $siesaStateBefore));

            return [
                'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
                'source' => $payload['source'] ?? null,
                'message' => 'El pedido ya existe en Siesa. Se sincronizaron indicadores legacy y se omite la retransmision.',
                'errors' => [],
                'siesa_web_service' => null,
                'import_payload' => null,
                'line_count' => 0,
                'order_line_count' => 0,
                'customer_sync_line_count' => 0,
                'detail_count' => 0,
                'order_reference' => $this->buildReference($payload),
                'pre_migration' => $preMigrationSnapshot,
                'customer_sync' => [
                    'status' => 'skipped',
                    'line_count' => 0,
                    'lines' => [],
                    'reason' => 'order_already_exists_in_siesa',
                ],
                'siesa_state' => $siesaStateBefore->toArray(),
                'legacy_net_total' => null,
                'already_migrated' => true,
                'timings_ms' => $timings,
            ];
        }

        $measure('validate_soap_configuration', fn () => $this->soapConfigurationValidator->validate());
        $measure('mark_migration_started', fn () => $this->legacyStateService->markMigrationStarted($payload));
        $importSucceeded = false;
        $legacyNetTotal = null;
        $siesaStateAfter = $siesaStateBefore;

        try {
            $customerSync = $measure('customer_sync', fn () => $this->customerSyncService->sync($payload, $header));
            $details = $measure('find_details', fn () => $this->repository->findDetails($payload));
            $orderLines = $measure('build_order_lines', fn () => $this->lineFactory->build($payload, $header, $orderRecord, $details));
            $lines = array_merge($customerSync['lines'] ?? [], $orderLines);
            $audit = $measure('audit_import', fn () => $this->auditService->import($lines, [
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
                $measure('update_enterprise_row_id', fn () => $this->legacyStateService->updateEnterpriseRowId($payload, $siesaStateAfter));

                $legacyNetTotal = $measure('compute_legacy_net_total', fn () => $this->legacyStateService->computeLegacyNetTotal($payload));
                $enterpriseNetTotal = (float) ($siesaStateAfter->netTotal ?? 0);
                $difference = abs($enterpriseNetTotal - $legacyNetTotal);
                $isGift = (int) ($header->PE_IndicadorObsequio ?? 0) === 1;

                if ($difference < $this->legacyStateService->verificationThreshold() || $isGift) {
                    $measure('mark_verified', fn () => $this->legacyStateService->markVerified($payload, $siesaStateAfter, $legacyNetTotal));
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
