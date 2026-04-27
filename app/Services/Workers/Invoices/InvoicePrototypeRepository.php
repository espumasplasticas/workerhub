<?php

namespace App\Services\Workers\Invoices;

use App\Exceptions\WorkerTaskProcessingException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use stdClass;

class InvoicePrototypeRepository
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
            ->where('FE_CentroOperativo', $reference['operational_center'])
            ->where('FE_TipoDocumento', $reference['document_type'])
            ->where('FE_NumeroDocumento', $reference['document_number'])
            ->first();

        if (!$record instanceof stdClass) {
            throw new WorkerTaskProcessingException(
                'No se encontro la factura en la vista prototipo de encabezado.',
                ['reference' => $reference]
            );
        }

        return $record;
    }

    /**
     * Completa el payload de invoice_migration usando el rowid como fuente de verdad.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function hydratePayloadFromInvoiceId(array $payload): array
    {
        $dbConnection = trim((string) ($payload['db_connection'] ?? ''));
        $invoiceId = $payload['invoice_id'] ?? null;

        if ($dbConnection === '' || !is_numeric($invoiceId)) {
            throw new WorkerTaskProcessingException(
                'El payload de invoice_migration requiere db_connection y invoice_id para resolver la factura.',
                ['payload' => $payload]
            );
        }

        $record = $this->connectionFor($dbConnection)
            ->table($this->invoicesTable())
            ->select([
                'FE_rowid',
                'FE_CentroOperativo',
                'FE_TipoDocumento',
                'FE_NumeroDocumento',
                'FE_CodigoTercero',
                'FE_CodigoSucursal',
                'FE_TotalNeto',
            ])
            ->where('FE_rowid', (int) $invoiceId)
            ->first();

        if (!$record instanceof stdClass) {
            throw new WorkerTaskProcessingException(
                'No se encontro la factura origen para el rowid enviado a WorkerHub.',
                ['payload' => $payload, 'invoice_id' => (int) $invoiceId]
            );
        }

        $operationalCenter = trim((string) ($record->FE_CentroOperativo ?? ''));
        $documentType = trim((string) ($record->FE_TipoDocumento ?? ''));
        $documentNumber = trim((string) ($record->FE_NumeroDocumento ?? ''));

        return array_merge($payload, [
            'invoice_id' => (int) ($record->FE_rowid ?? $invoiceId),
            'document_id' => sprintf('%s-%s-%s', $operationalCenter, $documentType, $documentNumber),
            'operational_center' => $operationalCenter,
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'client_code' => trim((string) ($record->FE_CodigoTercero ?? ($payload['client_code'] ?? ''))),
            'client_branch' => trim((string) ($record->FE_CodigoSucursal ?? ($payload['client_branch'] ?? '001'))),
            'invoice_total' => (float) ($record->FE_TotalNeto ?? ($payload['invoice_total'] ?? 0)),
        ]);
    }

    /**
     * @return list<stdClass>
     */
    public function findDetails(array $payload): array
    {
        $reference = $this->resolveReference($payload);

        $records = $this->connectionFor($reference['db_connection'])
            ->table($this->detailView())
            ->where('FD_CentroOperativo', $reference['operational_center'])
            ->where('FD_TipoDocumento', $reference['document_type'])
            ->where('FD_NumeroDocumento', $reference['document_number'])
            ->get()
            ->all();

        if ($records === []) {
            throw new WorkerTaskProcessingException(
                'No se encontraron lineas de la factura en la vista prototipo.',
                ['reference' => $reference]
            );
        }

        return array_values(array_filter($records, static fn ($record) => $record instanceof stdClass));
    }

    /**
     * @return list<stdClass>
     */
    public function findPayments(array $payload): array
    {
        $reference = $this->resolveReference($payload);

        return array_values(array_filter(
            $this->connectionFor($reference['db_connection'])
                ->table($this->paymentsView())
                ->where('F350_ID_CO', $reference['operational_center'])
                ->where('F350_ID_TIPO_DOCTO', $reference['document_type'])
                ->where('F350_CONSEC_DOCTO', $reference['document_number'])
                ->get()
                ->all(),
            static fn ($record) => $record instanceof stdClass
        ));
    }

    public function findInvoiceRecord(array $payload): stdClass
    {
        $reference = $this->resolveReference($payload);

        $record = $this->connectionFor($reference['db_connection'])
            ->table($this->invoicesTable())
            ->where('FE_CentroOperativo', $reference['operational_center'])
            ->where('FE_TipoDocumento', $reference['document_type'])
            ->where('FE_NumeroDocumento', $reference['document_number'])
            ->first();

        if (!$record instanceof stdClass) {
            throw new WorkerTaskProcessingException(
                'No se encontro la factura en la tabla legacy POS.',
                ['reference' => $reference]
            );
        }

        return $record;
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

        foreach ([
            'db_connection' => $dbConnection,
            'operational_center' => $operationalCenter,
            'document_type' => $documentType,
            'document_number' => $documentNumber,
        ] as $field => $value) {
            if ($value === '') {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            throw new WorkerTaskProcessingException(
                'El payload de invoice_migration esta incompleto. Configura: ' . implode(', ', $missing) . '.',
                ['missing_fields' => $missing, 'payload' => $payload]
            );
        }

        return [
            'db_connection' => $dbConnection,
            'operational_center' => $operationalCenter,
            'document_type' => $documentType,
            'document_number' => $documentNumber,
        ];
    }

    private function connectionFor(string $sourceConnection): ConnectionInterface
    {
        $configuredConnections = (array) $this->config->get('workerhub.invoices.source_connections', []);
        $connection = (string) ($configuredConnections[$sourceConnection] ?? $sourceConnection);

        if (!$this->database->connection($connection)->getPdo()) {
            throw new WorkerTaskProcessingException(
                sprintf('No se pudo abrir la conexion de origen para facturas: %s.', $connection),
                ['source_connection' => $sourceConnection, 'resolved_connection' => $connection]
            );
        }

        return $this->database->connection($connection);
    }

    private function headerView(): string
    {
        return (string) $this->config->get('workerhub.invoices.views.header');
    }

    private function detailView(): string
    {
        return (string) $this->config->get('workerhub.invoices.views.detail');
    }

    private function paymentsView(): string
    {
        return (string) $this->config->get('workerhub.invoices.views.payments');
    }

    private function invoicesTable(): string
    {
        return (string) $this->config->get('workerhub.invoices.tables.invoices', 'pos.facturas_encabezado');
    }
}
