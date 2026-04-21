<?php

namespace App\Services\Workers\Invoices;

use App\Data\Invoices\InvoiceSiesaStateSnapshot;
use App\Exceptions\WorkerTaskProcessingException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use stdClass;

class InvoiceSiesaStateService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config
    ) {
    }

    public function fetch(array $payload, stdClass $header): InvoiceSiesaStateSnapshot
    {
        $reference = $this->resolveReference($payload);
        $connection = $this->connectionFor($reference['db_connection']);
        $co = trim((string) ($header->F350_ID_CO ?? ''));
        $type = trim((string) ($header->F350_ID_TIPO_DOCTO ?? ''));
        $number = trim((string) ($header->F350_CONSEC_DOCTO ?? ''));

        if ($co === '' || $type === '' || $number === '') {
            throw new WorkerTaskProcessingException('El encabezado de la factura no tiene informacion suficiente para validar existencia en Siesa.', [
                'reference' => $reference,
            ]);
        }

        $row = $connection->selectOne(
            sprintf(
                "SELECT TOP 1
                    RTRIM(t350.f350_id_co) AS f350_id_co,
                    RTRIM(t350.f350_id_tipo_docto) AS f350_id_tipo_docto,
                    RTRIM(CONVERT(varchar(50), t350.f350_consec_docto)) AS f350_consec_docto,
                    t461.f461_rowid AS f461_rowid,
                    t350.f350_ind_estado,
                    t350.f350_total_db AS net_total
                FROM %s AS t461
                INNER JOIN %s AS t350 ON t461.f461_rowid_docto = t350.f350_rowid
                WHERE RTRIM(t350.f350_id_co) = ?
                  AND RTRIM(t350.f350_id_tipo_docto) = ?
                  AND RTRIM(CONVERT(varchar(50), t350.f350_consec_docto)) = ?",
                $this->commercialDocumentsTable(),
                $this->documentHeadersTable()
            ),
            [$co, $type, $number]
        );

        return new InvoiceSiesaStateSnapshot(
            operationalCenter: $reference['operational_center'],
            documentType: $reference['document_type'],
            documentNumber: $reference['document_number'],
            exists: $row !== null,
            enterpriseOperationalCenter: $row?->f350_id_co !== null ? trim((string) $row->f350_id_co) : $co,
            enterpriseDocumentType: $row?->f350_id_tipo_docto !== null ? trim((string) $row->f350_id_tipo_docto) : $type,
            enterpriseDocumentNumber: $row?->f350_consec_docto !== null ? trim((string) $row->f350_consec_docto) : $number,
            rowId: $row?->f461_rowid !== null ? (int) $row->f461_rowid : null,
            netTotal: $row?->net_total !== null ? (float) $row->net_total : null,
            stateIndicator: $row?->f350_ind_estado !== null ? (int) $row->f350_ind_estado : null,
        );
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
                'El payload de invoice_migration esta incompleto para validar existencia en Siesa. Configura: ' . implode(', ', $missing) . '.',
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
                sprintf('No se pudo abrir la conexion de origen para validar existencia de la factura en Siesa: %s.', $connection),
                ['source_connection' => $sourceConnection, 'resolved_connection' => $connection]
            );
        }

        return $this->database->connection($connection);
    }

    private function commercialDocumentsTable(): string
    {
        return (string) $this->config->get('workerhub.invoices.enterprise_state.tables.commercial_documents', 'SiesaEnterprise.dbo.t461_cm_docto_factura');
    }

    private function documentHeadersTable(): string
    {
        return (string) $this->config->get('workerhub.invoices.enterprise_state.tables.document_headers', 'SiesaEnterprise.dbo.t350_co_docto_contable');
    }
}
