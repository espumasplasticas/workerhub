<?php

namespace App\Services\Workers\Orders;

use App\Data\Orders\OrderSiesaStateSnapshot;
use App\Exceptions\WorkerTaskProcessingException;
use App\Services\Workers\SiesaImportAuditService;
use Epsalibrary\Siesa\Connectors\ConectorPedidoCompromiso;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use stdClass;

class OrderCancellationCommitmentReleaseService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
        private readonly SiesaImportAuditService $auditService
    ) {
    }

    /**
     * Descompromete el pedido en Siesa cuando el estado actual es comprometido.
     *
     * @param array<string, mixed> $payload
     */
    public function releaseIfCommitted(array $payload, OrderSiesaStateSnapshot $snapshot): int
    {
        if ((int) ($snapshot->stateIndicator ?? 0) !== 3) {
            return 0;
        }

        $connection = $this->connectionFor(trim((string) ($payload['db_connection'] ?? '')));
        $detailRows = $this->findCommittedDetailRows($connection, (int) $snapshot->rowId);
        $lines = [];

        foreach ($detailRows as $detailRow) {
            if ((int) ($detailRow->f431_ind_estado ?? 0) !== 3) {
                continue;
            }

            $lines[] = ConectorPedidoCompromiso::instance(
                trim((string) $snapshot->enterpriseOperationalCenter),
                trim((string) $snapshot->enterpriseDocumentType),
                trim((string) $snapshot->enterpriseDocumentNumber),
                (int) ($detailRow->f431_rowid ?? 0),
                trim((string) ($detailRow->f431_id_item ?? '')),
                trim((string) ($detailRow->f431_id_ext1_detalle ?? '')),
                trim((string) ($detailRow->f431_id_bodega ?? '')),
                trim((string) ($detailRow->f431_id_unidad_medida ?? '')),
                0,
                trim((string) ($detailRow->f431_id_ubicacion_aux ?? '')) !== ''
                    ? trim((string) $detailRow->f431_id_ubicacion_aux)
                    : null
            )->obtenerLinea();
        }

        if ($lines === []) {
            return 0;
        }

        $audit = $this->auditService->import($lines, [
            'worker_task_id' => $payload['_workerhub_task_id'] ?? null,
            'task_type' => $payload['_workerhub_task_type'] ?? 'order_cancellation',
            'document_id' => $payload['document_id'] ?? '',
            'source' => $payload['source'] ?? null,
            'import_stage' => 'order_cancellation_release_commitment',
            'line_count' => count($lines),
        ]);

        if (!$audit->result->success) {
            throw new WorkerTaskProcessingException(
                'Error al descomprometer el pedido antes de anularlo en Siesa: ' . $audit->result->message,
                [
                    'errors' => $audit->result->errors,
                    'payload' => $payload,
                    'siesa_web_service' => $audit->log->toArray(),
                ]
            );
        }

        return count($lines);
    }

    /**
     * @return list<stdClass>
     */
    private function findCommittedDetailRows(ConnectionInterface $connection, int $rowId): array
    {
        if ($rowId <= 0) {
            return [];
        }

        $rows = $connection->select(
            sprintf(
                "SELECT
                    f431_rowid,
                    RTRIM(CONVERT(varchar(50), f431_id_item)) AS f431_id_item,
                    RTRIM(ISNULL(f431_id_ext1_detalle, '')) AS f431_id_ext1_detalle,
                    RTRIM(ISNULL(f431_id_bodega, '')) AS f431_id_bodega,
                    RTRIM(ISNULL(f431_id_unidad_medida, '')) AS f431_id_unidad_medida,
                    RTRIM(ISNULL(f431_id_ubicacion_aux, '')) AS f431_id_ubicacion_aux,
                    f431_ind_estado
                FROM %s
                WHERE f431_rowid_pv_docto = ?",
                $this->enterpriseOrderLinesTable()
            ),
            [$rowId]
        );

        return array_values(array_filter($rows, static fn ($row) => $row instanceof stdClass));
    }

    private function connectionFor(string $sourceConnection): ConnectionInterface
    {
        $configuredConnections = (array) $this->config->get('workerhub.orders.source_connections', []);
        $connection = (string) ($configuredConnections[$sourceConnection] ?? $sourceConnection);

        if (!$this->database->connection($connection)->getPdo()) {
            throw new WorkerTaskProcessingException(
                sprintf('No se pudo abrir la conexion de origen para descomprometer el pedido: %s.', $connection),
                ['source_connection' => $sourceConnection, 'resolved_connection' => $connection]
            );
        }

        return $this->database->connection($connection);
    }

    private function enterpriseOrderLinesTable(): string
    {
        return (string) $this->config->get('workerhub.orders.enterprise_state.tables.order_lines', 'SiesaEnterprise.dbo.t431_cm_pv_movto');
    }
}
