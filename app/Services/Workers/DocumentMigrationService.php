<?php

namespace App\Services\Workers;

use App\Exceptions\WorkerTaskProcessingException;
use Epsalibrary\Contracts\ImportManagerInterface;

class DocumentMigrationService
{
    public function __construct(
        private readonly ImportManagerInterface $importManager,
        private readonly EpsaSoapConfigurationValidator $soapConfigurationValidator
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

        $result = $this->importManager->import($normalizedLines);

        if (!$result->success) {
            throw new WorkerTaskProcessingException(
                $result->message,
                [
                    'errors' => $result->errors,
                    'payload' => $payload,
                    'xml_payload' => $result->payload,
                ]
            );
        }

        return [
            'document_id' => $payload['document_id'] ?? null,
            'source' => $payload['source'] ?? null,
            'message' => $result->message,
            'errors' => $result->errors,
            'import_payload' => $result->payload,
            'line_count' => count($normalizedLines),
        ];
    }
}
