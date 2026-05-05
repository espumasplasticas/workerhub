<?php

namespace App\Services\Workers;

use App\Exceptions\WorkerTaskProcessingException;

class DocumentMigrationService
{
    public function __construct(
        private readonly SiesaImportAuditService $auditService,
        private readonly EpsaSoapConfigurationValidator $soapConfigurationValidator,
    )
    {
    }

    public function handle(array $payload): array
    {
        $lines = $payload['lines'] ?? null;

        if (!is_array($lines) || $lines === []) {
            throw new WorkerTaskProcessingException(
                'El payload de document_migration debe incluir lines como arreglo no vacio.',
                ['payload' => $payload]
            );
        }

        $normalizedLines = array_map(static function ($line): string {
            if (!is_scalar($line)) {
                throw new WorkerTaskProcessingException('Cada linea a importar debe ser escalar.');
            }

            return (string) $line;
        }, $lines);

        $this->soapConfigurationValidator->validate();

        $audit = $this->auditService->import($normalizedLines, [
            'worker_task_id' => $payload['_workerhub_task_id'] ?? null,
            'task_type' => $payload['_workerhub_task_type'] ?? 'document_migration',
            'document_id' => $payload['document_id'] ?? null,
            'source' => $payload['source'] ?? null,
            'import_stage' => 'document_migration',
            'line_count' => count($normalizedLines),
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
            'document_id' => $payload['document_id'] ?? null,
            'source' => $payload['source'] ?? null,
            'message' => $result->message,
            'errors' => $result->errors,
            'siesa_web_service' => $audit->log->toArray(),
            'import_payload' => $result->payload,
            'line_count' => count($normalizedLines),
        ];
    }
}
