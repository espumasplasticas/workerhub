<?php

namespace App\Services\Workers;

use App\Exceptions\WorkerTaskProcessingException;
use App\Services\Workers\Orders\OrderCancellationCommitmentReleaseService;
use App\Services\Workers\Orders\OrderCancellationOperationalSideEffectsService;
use App\Services\Workers\Orders\OrderLegacyStateService;
use App\Services\Workers\Orders\OrderLineFactory;
use App\Services\Workers\Orders\OrderPrototypeRepository;
use App\Services\Workers\Orders\OrderSiesaStateService;

class OrderCancellationService
{
    public function __construct(
        private readonly SiesaImportAuditService $auditService,
        private readonly EpsaSoapConfigurationValidator $soapConfigurationValidator,
        private readonly OrderPrototypeRepository $repository,
        private readonly OrderLineFactory $lineFactory,
        private readonly OrderSiesaStateService $siesaStateService,
        private readonly OrderLegacyStateService $legacyStateService,
        private readonly OrderCancellationCommitmentReleaseService $commitmentReleaseService,
        private readonly OrderCancellationOperationalSideEffectsService $operationalSideEffectsService
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
        $header = $measure('find_header', fn () => $this->repository->findHeader($payload));
        $siesaStateBefore = $measure('fetch_siesa_state_before', fn () => $this->siesaStateService->fetch($payload, $header));

        if (!$siesaStateBefore->exists) {
            throw new WorkerTaskProcessingException(
                'No se puede anular el pedido porque no existe en Siesa.',
                ['payload' => $payload, 'siesa_state' => $siesaStateBefore->toArray()]
            );
        }

        if ((int) ($siesaStateBefore->stateIndicator ?? 0) === 4) {
            throw new WorkerTaskProcessingException(
                'No se puede anular el pedido porque en Siesa ya esta cumplido.',
                ['payload' => $payload, 'siesa_state' => $siesaStateBefore->toArray()]
            );
        }

        if ((int) ($siesaStateBefore->stateIndicator ?? 0) === 9) {
            $measure('mark_cancelled_detected', fn () => $this->legacyStateService->markCancelled($payload, $siesaStateBefore));
            $measure('apply_operational_side_effects_detected', fn () => $this->operationalSideEffectsService->applyPostCancellationSideEffects($payload, $siesaStateBefore));

            return [
                'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
                'message' => 'El pedido ya estaba anulado en Siesa. Se sincronizaron indicadores legacy.',
                'errors' => [],
                'line_count' => 0,
                'siesa_state' => $siesaStateBefore->toArray(),
                'already_cancelled' => true,
                'timings_ms' => $timings,
            ];
        }

        $measure('validate_soap_configuration', fn () => $this->soapConfigurationValidator->validate());
        $measure('mark_cancellation_requested', fn () => $this->legacyStateService->markCancellationRequested($payload));
        $releasedCommitmentLineCount = $measure('release_commitment', fn () => $this->commitmentReleaseService->releaseIfCommitted($payload, $siesaStateBefore));
        $lines = $measure('build_cancellation_lines', fn () => $this->lineFactory->buildCancellation($payload, $header, $orderRecord));
        $audit = $measure('audit_import', fn () => $this->auditService->import($lines, [
            'worker_task_id' => $payload['_workerhub_task_id'] ?? null,
            'task_type' => $payload['_workerhub_task_type'] ?? 'order_cancellation',
            'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
            'source' => $payload['source'] ?? null,
            'import_stage' => 'order_cancellation',
            'line_count' => count($lines),
        ]));

        if (!$audit->result->success) {
            throw new WorkerTaskProcessingException(
                $audit->result->message,
                [
                    'errors' => $audit->result->errors,
                    'payload' => $payload,
                    'siesa_web_service' => $audit->log->toArray(),
                ]
            );
        }

        $siesaStateAfter = $measure('fetch_siesa_state_after', fn () => $this->siesaStateService->fetch($payload, $header));
        $measure('mark_cancelled', fn () => $this->legacyStateService->markCancelled($payload, $siesaStateAfter));
        $measure('apply_operational_side_effects', fn () => $this->operationalSideEffectsService->applyPostCancellationSideEffects($payload, $siesaStateAfter));

        return [
            'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
            'message' => $audit->result->message,
            'errors' => $audit->result->errors,
            'line_count' => count($lines),
            'released_commitment_line_count' => $releasedCommitmentLineCount,
            'siesa_web_service' => $audit->log->toArray(),
            'siesa_state' => $siesaStateAfter->toArray(),
            'timings_ms' => $timings,
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
