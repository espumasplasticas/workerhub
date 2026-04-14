<?php

namespace App\Services\Workers\Receipts;

use App\Exceptions\WorkerTaskProcessingException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use stdClass;

class ReceiptPrototypeRepository
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config
    ) {
    }

    public function findHeader(array $payload): stdClass
    {
        $reference = $this->resolveReference($payload);

        $record = $this->connectionFor($reference['db_connection'])
            ->table($this->headerView())
            ->where('RE_CentroOperativo', $reference['operational_center'])
            ->where('RE_TipoDocumento', $reference['document_type'])
            ->where('RE_NumeroDocumento', $reference['document_number'])
            ->first();

        if (!$record instanceof stdClass) {
            throw new WorkerTaskProcessingException(
                'No se encontro el recibo en la vista prototipo de encabezado.',
                ['reference' => $reference]
            );
        }

        return $record;
    }

    /**
     * @return list<stdClass>
     */
    public function findPayments(array $payload): array
    {
        $reference = $this->resolveReference($payload);

        $records = $this->connectionFor($reference['db_connection'])
            ->table($this->paymentsView())
            ->where('RE_CentroOperativo', $reference['operational_center'])
            ->where('RE_TipoDocumento', $reference['document_type'])
            ->where('RE_NumeroDocumento', $reference['document_number'])
            ->orderBy('RD_NumeroDeRegistro')
            ->get()
            ->all();

        if ($records === []) {
            throw new WorkerTaskProcessingException(
                'No se encontraron medios de pago del recibo en la vista prototipo.',
                ['reference' => $reference]
            );
        }

        return $records;
    }

    /**
     * @return array{db_connection: string, operational_center: string, document_type: string, document_number: string}
     */
    private function resolveReference(array $payload): array
    {
        $dbConnection = trim((string) ($payload['db_connection'] ?? ''));
        $operationalCenter = trim((string) ($payload['operational_center'] ?? ''));
        $documentType = trim((string) ($payload['document_type'] ?? ''));
        $documentNumber = trim((string) ($payload['document_number'] ?? ''));

        $missing = [];

        if ($dbConnection === '') {
            $missing[] = 'db_connection';
        }

        if ($operationalCenter === '') {
            $missing[] = 'operational_center';
        }

        if ($documentType === '') {
            $missing[] = 'document_type';
        }

        if ($documentNumber === '') {
            $missing[] = 'document_number';
        }

        if ($missing !== []) {
            throw new WorkerTaskProcessingException(
                'El payload de receipt_migration esta incompleto. Configura: ' . implode(', ', $missing) . '.',
                [
                    'missing_fields' => $missing,
                    'payload' => $payload,
                ]
            );
        }

        return [
            'db_connection' => $dbConnection,
            'operational_center' => $operationalCenter,
            'document_type' => $documentType,
            'document_number' => $documentNumber,
        ];
    }

    private function connectionFor(string $sourceConnection): \Illuminate\Database\ConnectionInterface
    {
        $configuredConnections = (array) $this->config->get('workerhub.receipts.source_connections', []);
        $connection = (string) ($configuredConnections[$sourceConnection] ?? $sourceConnection);

        if (!$this->database->connection($connection)->getPdo()) {
            throw new WorkerTaskProcessingException(
                sprintf('No se pudo abrir la conexion de origen para recibos: %s.', $connection),
                ['source_connection' => $sourceConnection, 'resolved_connection' => $connection]
            );
        }

        return $this->database->connection($connection);
    }

    private function headerView(): string
    {
        return (string) $this->config->get('workerhub.receipts.views.header');
    }

    private function paymentsView(): string
    {
        return (string) $this->config->get('workerhub.receipts.views.payments');
    }
}
