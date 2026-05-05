<?php

namespace App\Services\Workers\Receipts;

use App\Contracts\ReceiptCrossReferenceDataSourceInterface;
use App\Data\Receipts\ReceiptCrossReferenceSnapshot;
use App\Exceptions\WorkerTaskProcessingException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use stdClass;

class SqlReceiptCrossReferenceDataSource implements ReceiptCrossReferenceDataSourceInterface
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config
    ) {
    }

    public function fetch(array $payload, stdClass $receiptHeader): ReceiptCrossReferenceSnapshot
    {
        $reference = $this->resolveReference($payload);
        $connection = $this->connectionFor($reference['db_connection']);

        $auxiliaryId = trim((string) $this->config->get('workerhub.receipts.cross_reference.auxiliary_id', '28050505'));
        $operationalCenter = trim((string) ($receiptHeader->F350_ID_CO ?? ''));
        $unit = trim((string) $this->config->get('workerhub.receipts.cross_reference.unit', '02'));
        $branch = str_pad(trim((string) ($receiptHeader->F353_ID_SUCURSAL_DOCTO_CRUCE ?? '')), 3, '0', STR_PAD_LEFT);
        $documentType = trim((string) ($receiptHeader->F350_ID_TIPO_DOCTO ?? ''));
        $documentNumber = trim((string) ($receiptHeader->F350_CONSEC_DOCTO ?? ''));

        if ($operationalCenter === '' || $documentType === '' || $documentNumber === '') {
            throw new WorkerTaskProcessingException(
                'El encabezado del recibo no tiene informacion suficiente para validar el documento de cruce.',
                [
                    'operational_center' => $operationalCenter,
                    'document_type' => $documentType,
                    'document_number' => $documentNumber,
                ]
            );
        }

        $exists = $connection->selectOne(
            sprintf(
                "SELECT TOP 1 1 AS exists_flag
                FROM %s AS t353
                INNER JOIN %s AS t253 ON t353.f353_rowid_auxiliar = t253.f253_rowid
                WHERE RTRIM(t253.f253_id) = ?
                  AND RTRIM(t353.f353_id_co_cruce) = ?
                  AND RTRIM(t353.f353_id_un_cruce) = ?
                  AND RTRIM(t353.f353_id_sucursal) = ?
                  AND RTRIM(t353.f353_id_tipo_docto_cruce) = ?
                  AND RTRIM(CONVERT(varchar(50), t353.f353_consec_docto_cruce)) = ?
                  AND (t353.f353_total_db - t353.f353_total_cr) <> 0",
                $this->openBalancesTable(),
                $this->auxiliariesTable()
            ),
            [$auxiliaryId, $operationalCenter, $unit, $branch, $documentType, $documentNumber]
        ) !== null;

        return new ReceiptCrossReferenceSnapshot(
            auxiliaryId: $auxiliaryId,
            operationalCenter: $operationalCenter,
            unit: $unit,
            branch: $branch,
            documentType: $documentType,
            documentNumber: $documentNumber,
            exists: $exists,
        );
    }

    /**
     * @return array{db_connection: string}
     */
    private function resolveReference(array $payload): array
    {
        $dbConnection = trim((string) ($payload['db_connection'] ?? ''));

        if ($dbConnection === '') {
            throw new WorkerTaskProcessingException(
                'El payload de receipt_migration no tiene db_connection para validar el documento de cruce.',
                ['payload' => $payload]
            );
        }

        return ['db_connection' => $dbConnection];
    }

    private function connectionFor(string $sourceConnection): ConnectionInterface
    {
        $configuredConnections = (array) $this->config->get('workerhub.receipts.source_connections', []);
        $connection = (string) ($configuredConnections[$sourceConnection] ?? $sourceConnection);

        if (!$this->database->connection($connection)->getPdo()) {
            throw new WorkerTaskProcessingException(
                sprintf('No se pudo abrir la conexion de origen para validar cruces de recibos: %s.', $connection),
                ['source_connection' => $sourceConnection, 'resolved_connection' => $connection]
            );
        }

        return $this->database->connection($connection);
    }

    private function openBalancesTable(): string
    {
        return (string) $this->config->get('workerhub.receipts.cross_reference.enterprise_tables.open_balances', 'SiesaEnterprise.dbo.t353_co_saldo_abierto');
    }

    private function auxiliariesTable(): string
    {
        return (string) $this->config->get('workerhub.receipts.cross_reference.enterprise_tables.auxiliaries', 'SiesaEnterprise.dbo.t253_co_auxiliares');
    }
}
