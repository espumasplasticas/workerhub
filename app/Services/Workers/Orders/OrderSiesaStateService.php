<?php

namespace App\Services\Workers\Orders;

use App\Data\Orders\OrderSiesaStateSnapshot;
use App\Exceptions\WorkerTaskProcessingException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use stdClass;

class OrderSiesaStateService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config
    ) {
    }

    public function fetch(array $payload, stdClass $header): OrderSiesaStateSnapshot
    {
        $reference = $this->resolveReference($payload);
        $connection = $this->connectionFor($reference['db_connection']);

        $candidates = array_filter([
            [
                'co' => trim((string) ($header->f430_id_co ?? '')),
                'type' => trim((string) ($header->f430_id_tipo_docto ?? '')),
                'number' => trim((string) ($header->f430_consec_docto ?? '')),
            ],
            [
                'co' => $reference['operational_center'],
                'type' => 'P' . $reference['document_type'],
                'number' => $reference['document_number'],
            ],
            [
                'co' => $reference['document_type'],
                'type' => 'PS',
                'number' => $reference['document_number'],
            ],
            [
                'co' => $reference['operational_center'],
                'type' => $reference['document_type'] . 'M',
                'number' => $reference['document_number'],
            ],
        ], static fn (array $candidate): bool => $candidate['co'] !== '' && $candidate['type'] !== '' && $candidate['number'] !== '');

        $row = null;

        foreach ($candidates as $candidate) {
            $row = $this->findOrder($connection, $candidate['co'], $candidate['type'], $candidate['number']);

            if ($row instanceof stdClass) {
                break;
            }
        }

        if ($row === null) {
            // Some duplicate import failures are caused by a purchase order / load order
            // reference that already exists in Siesa. In that case the document may be
            // present under a different enterprise identity, so search by legacy reference.
            $row = $this->findOrderByLegacyReference($connection, $reference['operational_center'], $header);
        }

        return new OrderSiesaStateSnapshot(
            operationalCenter: $reference['operational_center'],
            documentType: $reference['document_type'],
            documentNumber: $reference['document_number'],
            exists: $row !== null,
            enterpriseOperationalCenter: $row?->f430_id_co !== null ? trim((string) $row->f430_id_co) : null,
            enterpriseDocumentType: $row?->f430_id_tipo_docto !== null ? trim((string) $row->f430_id_tipo_docto) : null,
            enterpriseDocumentNumber: $row?->f430_consec_docto !== null ? trim((string) $row->f430_consec_docto) : null,
            rowId: $row?->f430_rowid !== null ? (int) $row->f430_rowid : null,
            // Legacy verification reads the net amount from t431 detail rows, not from t430.
            netTotal: $row?->f430_rowid !== null ? $this->findNetTotal($connection, (int) $row->f430_rowid) : null,
            stateIndicator: $row?->f430_ind_estado !== null ? (int) $row->f430_ind_estado : null,
        );
    }

    private function findOrder(ConnectionInterface $connection, string $co, string $type, string $number): ?stdClass
    {
        return $connection->selectOne(
            sprintf(
                "SELECT TOP 1
                    RTRIM(f430_id_co) AS f430_id_co,
                    RTRIM(f430_id_tipo_docto) AS f430_id_tipo_docto,
                    RTRIM(CONVERT(varchar(50), f430_consec_docto)) AS f430_consec_docto,
                    f430_rowid,
                    f430_ind_estado
                FROM %s
                WHERE RTRIM(f430_id_co) = ?
                  AND RTRIM(f430_id_tipo_docto) = ?
                  AND RTRIM(CONVERT(varchar(50), f430_consec_docto)) = ?",
                $this->enterpriseOrdersTable()
            ),
            [$co, $type, $number]
        );
    }

    private function findOrderByLegacyReference(ConnectionInterface $connection, string $operationalCenter, stdClass $header): ?stdClass
    {
        $referenceDocumentNumber = $this->firstNonEmpty(
            $header->f430_num_docto_referencia ?? null,
            $header->PE_OrdenDeCompra ?? null,
            $header->PE_OrdenDeCargue ?? null
        );

        $reference = $this->firstNonEmpty(
            $header->f430_referencia ?? null,
            $header->PE_OrdenDeCargue ?? null,
            $header->PE_OrdenDeCompra ?? null
        );

        if ($referenceDocumentNumber === '' && $reference === '') {
            return null;
        }

        $conditions = [];
        $bindings = [];

        if ($referenceDocumentNumber !== '') {
            $conditions[] = 'RTRIM(f430_num_docto_referencia) = ?';
            $bindings[] = $referenceDocumentNumber;
        }

        if ($reference !== '') {
            $conditions[] = 'RTRIM(f430_referencia) = ?';
            $bindings[] = $reference;
        }

        if ($conditions === []) {
            return null;
        }

        $where = implode(' OR ', $conditions);

        if ($operationalCenter !== '') {
            $where = sprintf('(%s) AND RTRIM(f430_id_co) = ?', $where);
            $bindings[] = $operationalCenter;
        }

        return $connection->selectOne(
            sprintf(
                "SELECT TOP 1
                    RTRIM(f430_id_co) AS f430_id_co,
                    RTRIM(f430_id_tipo_docto) AS f430_id_tipo_docto,
                    RTRIM(CONVERT(varchar(50), f430_consec_docto)) AS f430_consec_docto,
                    f430_rowid,
                    f430_ind_estado
                FROM %s
                WHERE %s",
                $this->enterpriseOrdersTable(),
                $where
            ),
            $bindings
        );
    }

    private function firstNonEmpty(mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function findNetTotal(ConnectionInterface $connection, int $rowId): ?float
    {
        $row = $connection->selectOne(
            sprintf(
                "SELECT SUM(f431_vlr_neto) AS net_total
                FROM %s
                WHERE f431_rowid_pv_docto = ?",
                $this->enterpriseOrderLinesTable()
            ),
            [$rowId]
        );

        if (!$row instanceof stdClass || $row->net_total === null) {
            return null;
        }

        return (float) $row->net_total;
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
                'El payload de order_migration esta incompleto para validar si el pedido ya existe en Siesa. Configura: ' . implode(', ', $missing) . '.',
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
        $configuredConnections = (array) $this->config->get('workerhub.orders.source_connections', []);
        $connection = (string) ($configuredConnections[$sourceConnection] ?? $sourceConnection);

        if (!$this->database->connection($connection)->getPdo()) {
            throw new WorkerTaskProcessingException(
                sprintf('No se pudo abrir la conexion de origen para validar existencia del pedido en Siesa: %s.', $connection),
                ['source_connection' => $sourceConnection, 'resolved_connection' => $connection]
            );
        }

        return $this->database->connection($connection);
    }

    private function enterpriseOrdersTable(): string
    {
        return (string) $this->config->get('workerhub.orders.enterprise_state.tables.orders', 'SiesaEnterprise.dbo.t430_cm_pv_docto');
    }

    private function enterpriseOrderLinesTable(): string
    {
        return (string) $this->config->get('workerhub.orders.enterprise_state.tables.order_lines', 'SiesaEnterprise.dbo.t431_cm_pv_movto');
    }
}
