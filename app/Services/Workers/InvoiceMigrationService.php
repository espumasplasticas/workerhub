<?php

namespace App\Services\Workers;

use App\Exceptions\WorkerTaskProcessingException;
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
        private readonly InvoicePrototypeRepository $repository,
        private readonly InvoiceLineFactory $lineFactory,
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
        $importSucceeded = false;

        try {
            $details = $measure('find_details', fn () => $this->repository->findDetails($payload));
            $payments = $measure('find_payments', fn () => $this->repository->findPayments($payload));
            $lines = $measure('build_invoice_lines', fn () => $this->lineFactory->build($header, $details, $payments));
            $audit = $measure('audit_import', fn () => $this->auditService->import($lines, [
                'worker_task_id' => $payload['_workerhub_task_id'] ?? null,
                'task_type' => $payload['_workerhub_task_type'] ?? 'invoice_migration',
                'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
                'source' => $payload['source'] ?? null,
                'import_stage' => 'invoice_migration',
                'line_count' => count($lines),
                'detail_count' => count($details),
                'payment_count' => count($payments),
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

            $measure('mark_migrated', fn () => $this->legacyStateService->markMigrated($payload));
            $importSucceeded = true;
            $siesaStateAfter = $measure('fetch_siesa_state_after', fn () => $this->siesaStateService->fetch($payload, $header));

            if ($siesaStateAfter->exists) {
                $measure('mark_detected_in_siesa_after_import', fn () => $this->legacyStateService->markDetectedInSiesa($payload, $siesaStateAfter));
            }

            return [
                'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
                'message' => $audit->result->message,
                'errors' => $audit->result->errors,
                'line_count' => count($lines),
                'detail_count' => count($details),
                'payment_count' => count($payments),
                'invoice_reference' => $this->buildReference($payload),
                'siesa_web_service' => $audit->log->toArray(),
                'siesa_state' => $siesaStateAfter->toArray(),
                'timings_ms' => $timings,
            ];
        } catch (Throwable $exception) {
            if (!$importSucceeded) {
                // The legacy flow retries invoices; do not mutate POS state to failure here.
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
