<?php

namespace App\Services\Workers;

use App\Exceptions\WorkerTaskProcessingException;
use App\Services\Workers\Receipts\ReceiptLegacyStateService;
use App\Services\Workers\Receipts\ReceiptLineFactory;
use App\Services\Workers\Receipts\ReceiptPrototypeRepository;
use App\Services\Workers\Receipts\ReceiptSiesaStateService;

class ReceiptCancellationService
{
    public function __construct(
        private readonly SiesaImportAuditService $auditService,
        private readonly EpsaSoapConfigurationValidator $soapConfigurationValidator,
        private readonly ReceiptPrototypeRepository $repository,
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

        $header = $measure('find_header', fn () => $this->repository->findHeader($payload));
        $siesaStateBefore = $measure('fetch_siesa_state_before', fn () => $this->siesaStateService->fetch($payload, $header));

        if (!$siesaStateBefore->exists) {
            throw new WorkerTaskProcessingException(
                'No se puede anular el recibo porque no existe en Siesa.',
                ['payload' => $payload, 'siesa_state' => $siesaStateBefore->toArray()]
            );
        }

        if ((int) ($siesaStateBefore->stateIndicator ?? 0) === 2) {
            $measure('mark_cancelled_detected', fn () => $this->legacyStateService->markCancelled($payload, 'Anulado desde Siesa'));

            return [
                'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
                'message' => 'El recibo ya estaba anulado en Siesa. Se sincronizaron indicadores legacy.',
                'errors' => [],
                'line_count' => 0,
                'siesa_state' => $siesaStateBefore->toArray(),
                'already_cancelled' => true,
                'timings_ms' => $timings,
            ];
        }

        if (trim((string) ($payload['document_type'] ?? '')) === 'RCM') {
            throw new WorkerTaskProcessingException(
                'Los recibos RCM deben anularse manualmente en Siesa.',
                ['payload' => $payload, 'siesa_state' => $siesaStateBefore->toArray()]
            );
        }

        $measure('validate_soap_configuration', fn () => $this->soapConfigurationValidator->validate());
        $measure('mark_cancellation_requested', fn () => $this->legacyStateService->markCancellationRequested($payload));
        $lines = $measure('build_cancellation_lines', fn () => $this->lineFactory->buildCancellation($header));
        $audit = $measure('audit_import', fn () => $this->auditService->import($lines, [
            'worker_task_id' => $payload['_workerhub_task_id'] ?? null,
            'task_type' => $payload['_workerhub_task_type'] ?? 'receipt_cancellation',
            'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
            'source' => $payload['source'] ?? null,
            'import_stage' => 'receipt_cancellation',
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
        $measure('mark_cancelled', fn () => $this->legacyStateService->markCancelled($payload));

        return [
            'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
            'message' => $audit->result->message,
            'errors' => $audit->result->errors,
            'line_count' => count($lines),
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
