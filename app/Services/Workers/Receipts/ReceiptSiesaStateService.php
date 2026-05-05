<?php

namespace App\Services\Workers\Receipts;

use App\Data\Receipts\ReceiptSiesaStateSnapshot;
use App\Exceptions\WorkerTaskProcessingException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use stdClass;

class ReceiptSiesaStateService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config
    ) {
    }

    public function fetch(array $payload, stdClass $receiptHeader): ReceiptSiesaStateSnapshot
    {
        $reference = $this->resolveReference($payload);
        $connection = $this->connectionFor($reference['db_connection']);
        $accountingOperationalCenter = trim((string) ($receiptHeader->F350_ID_CO ?? ''));
        $accountingDocumentType = trim((string) ($receiptHeader->F350_ID_TIPO_DOCTO ?? ''));
        $accountingDocumentNumber = trim((string) ($receiptHeader->F350_CONSEC_DOCTO ?? ''));

        if ($accountingOperationalCenter === '' || $accountingDocumentType === '' || $accountingDocumentNumber === '') {
            throw new WorkerTaskProcessingException(
                'El encabezado del recibo no tiene informacion suficiente para validar si ya existe en Siesa.',
                [
                    'reference' => $reference,
                    'accounting_operational_center' => $accountingOperationalCenter,
                    'accounting_document_type' => $accountingDocumentType,
                    'accounting_document_number' => $accountingDocumentNumber,
                ]
            );
        }

        $row = $this->findPrimaryState(
            $connection,
            $accountingOperationalCenter,
            $accountingDocumentType,
            $accountingDocumentNumber
        );

        if ($row === null) {
            $row = $this->findLegacyFallbackState(
                $connection,
                $reference['document_type'],
                $reference['document_number'],
                $accountingOperationalCenter,
                $accountingDocumentType
            );
        }

        return new ReceiptSiesaStateSnapshot(
            operationalCenter: $reference['operational_center'],
            documentType: $reference['document_type'],
            documentNumber: $reference['document_number'],
            exists: $row !== null,
            accountingOperationalCenter: $row?->cgdoc_k1_co !== null ? trim((string) $row->cgdoc_k1_co) : $accountingOperationalCenter,
            accountingDocumentType: $row?->cgdoc_k1_tipo !== null ? trim((string) $row->cgdoc_k1_tipo) : $accountingDocumentType,
            accountingDocumentNumber: $row?->cgdoc_k1_nro !== null ? trim((string) $row->cgdoc_k1_nro) : $accountingDocumentNumber,
            creditTotal: $row?->valorc !== null ? (float) $row->valorc : null,
            debitTotal: $row?->valord !== null ? (float) $row->valord : null,
            stateIndicator: $row?->f350_ind_estado !== null ? (int) $row->f350_ind_estado : null,
        );
    }

    private function findPrimaryState(
        ConnectionInterface $connection,
        string $operationalCenter,
        string $documentType,
        string $documentNumber
    ): ?stdClass {
        return $connection->selectOne(
            sprintf(
                "SELECT TOP 1
                    RTRIM(t350.f350_id_co) AS cgdoc_k1_co,
                    RTRIM(t350.f350_id_tipo_docto) AS cgdoc_k1_tipo,
                    RTRIM(CONVERT(varchar(50), t350.f350_consec_docto)) AS cgdoc_k1_nro,
                    t350.f350_total_cr AS valorc,
                    t350.f350_total_db AS valord,
                    t350.f350_ind_estado
                FROM %s AS t350
                INNER JOIN %s AS t357 ON t350.f350_rowid = t357.f357_rowid_docto
                WHERE RTRIM(t350.f350_id_co) = ?
                  AND RTRIM(t350.f350_id_tipo_docto) = ?
                  AND RTRIM(CONVERT(varchar(50), t350.f350_consec_docto)) = ?",
                $this->accountingDocumentsTable(),
                $this->cashReceiptsTable()
            ),
            [$operationalCenter, $documentType, $documentNumber]
        );
    }

    private function findLegacyFallbackState(
        ConnectionInterface $connection,
        string $sourceDocumentType,
        string $sourceDocumentNumber,
        string $accountingOperationalCenter,
        string $accountingDocumentType
    ): ?stdClass {
        if ($sourceDocumentType === 'RCM') {
            return $connection->selectOne(
                sprintf(
                    "SELECT TOP 1
                        RTRIM(t350.f350_id_co) AS cgdoc_k1_co,
                        RTRIM(t350.f350_id_tipo_docto) AS cgdoc_k1_tipo,
                        RTRIM(CONVERT(varchar(50), t350.f350_consec_docto)) AS cgdoc_k1_nro,
                        t350.f350_total_cr AS valorc,
                        t350.f350_total_db AS valord,
                        t350.f350_ind_estado
                    FROM %s AS t350
                    INNER JOIN %s AS t357 ON t350.f350_rowid = t357.f357_rowid_docto
                    WHERE RTRIM(t350.f350_id_co) = ?
                      AND RTRIM(t350.f350_id_tipo_docto) = ?
                      AND RTRIM(CONVERT(varchar(50), t357.f357_referencia)) = ?",
                    $this->accountingDocumentsTable(),
                    $this->cashReceiptsTable()
                ),
                [$accountingOperationalCenter, $accountingDocumentType, $sourceDocumentNumber]
            );
        }

        return null;
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
                'El payload de receipt_migration esta incompleto para validar si el recibo ya existe en Siesa. Configura: ' . implode(', ', $missing) . '.',
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

    private function connectionFor(string $sourceConnection): ConnectionInterface
    {
        $configuredConnections = (array) $this->config->get('workerhub.receipts.source_connections', []);
        $connection = (string) ($configuredConnections[$sourceConnection] ?? $sourceConnection);

        if (!$this->database->connection($connection)->getPdo()) {
            throw new WorkerTaskProcessingException(
                sprintf('No se pudo abrir la conexion de origen para validar existencia del recibo en Siesa: %s.', $connection),
                ['source_connection' => $sourceConnection, 'resolved_connection' => $connection]
            );
        }

        return $this->database->connection($connection);
    }

    private function accountingDocumentsTable(): string
    {
        return (string) $this->config->get(
            'workerhub.receipts.enterprise_state.tables.accounting_documents',
            'SiesaEnterprise.dbo.t350_co_docto_contable'
        );
    }

    private function cashReceiptsTable(): string
    {
        return (string) $this->config->get(
            'workerhub.receipts.enterprise_state.tables.cash_receipts',
            'SiesaEnterprise.dbo.t357_co_ingresos_caja'
        );
    }
}
