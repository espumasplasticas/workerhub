<?php

namespace App\Services\Workers\Orders;

use App\Data\Orders\OrderSiesaStateSnapshot;
use App\Services\Workers\SiesaImportAuditService;
use Epsalibrary\Siesa\Connectors\PrototipoDocumentoInventario;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Throwable;

class OrderCancellationOperationalSideEffectsService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
        private readonly SiesaImportAuditService $auditService,
        private readonly OrderDomicileBitacoraPreservationService $orderDomicileBitacoraPreservationService
    ) {
    }

    /**
     * Ejecuta side effects legacy no interactivos despues de anular el pedido en Siesa.
     *
     * @param array<string, mixed> $payload
     */
    public function applyPostCancellationSideEffects(array $payload, OrderSiesaStateSnapshot $snapshot): void
    {
        $connection = $this->connectionFor(trim((string) ($payload['db_connection'] ?? '')));

        $this->cancelAssemblyInventoryDocumentIfPresent($connection, $payload, $snapshot);
        $this->orderDomicileBitacoraPreservationService->preserveDomicileBitacorasAsOrderNotes($payload, $snapshot);
        $this->completeLinkedDomicileIfPresent($connection, $snapshot);
        $this->cancelProductionTicketsIfPresent($connection, $snapshot);
    }

    /**
     * El legacy intenta anular la salida KIS relacionada, pero no aborta toda la anulacion si falla.
     *
     * @param array<string, mixed> $payload
     */
    private function cancelAssemblyInventoryDocumentIfPresent(ConnectionInterface $connection, array $payload, OrderSiesaStateSnapshot $snapshot): void
    {
        try {
            $row = $connection->selectOne(
                sprintf(
                    "SELECT TOP 1
                        RTRIM(f350_id_co) AS f350_id_co,
                        RTRIM(f350_id_tipo_docto) AS f350_id_tipo_docto,
                        f350_consec_docto,
                        CONVERT(char(8), f350_fecha, 112) AS f350_fecha
                    FROM %s
                    WHERE RTRIM(f350_id_co) = ?
                      AND RTRIM(f350_id_tipo_docto) = 'KIS'
                      AND f350_notas LIKE ?
                      AND f350_notas LIKE ?
                      AND f350_notas LIKE ?
                      AND f350_ind_estado <> 2",
                    $this->enterpriseAccountingDocumentsTable()
                ),
                [
                    trim((string) $snapshot->enterpriseOperationalCenter),
                    '%' . trim((string) $snapshot->enterpriseOperationalCenter) . '%',
                    '%' . trim((string) $snapshot->enterpriseDocumentType) . '%',
                    '%' . trim((string) $snapshot->enterpriseDocumentNumber) . '%',
                ]
            );

            if (!$row instanceof \stdClass) {
                return;
            }

            $connector = new PrototipoDocumentoInventario();
            $line = $connector->asignarEncabezadoDocucmento(
                'KIS',
                $row->f350_consec_docto,
                (string) $row->f350_fecha,
                sprintf('SALIDA DE ENSAMBLE AUTOMATICO CRUZA CON DOC KIE %s', $row->f350_consec_docto),
                '70',
                '610',
                2
            );
            $line = substr($line, 0, 11) . '1' . substr($line, 12);

            $this->auditService->import([$line], [
                'worker_task_id' => $payload['_workerhub_task_id'] ?? null,
                'task_type' => $payload['_workerhub_task_type'] ?? 'order_cancellation',
                'document_id' => $payload['document_id'] ?? '',
                'source' => $payload['source'] ?? null,
                'import_stage' => 'order_cancellation_cancel_assembly',
                'line_count' => 1,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function completeLinkedDomicileIfPresent(ConnectionInterface $connection, OrderSiesaStateSnapshot $snapshot): void
    {
        $enterpriseOrderKey = $this->buildEnterpriseOrderKey($snapshot);
        if ($enterpriseOrderKey === '') {
            return;
        }

        $domicile = $connection->table($this->domicileOrderTable())
            ->select(['DP_TipoId', 'DP_Id'])
            ->where('DP_NumeroPedidoEnterprise', $enterpriseOrderKey)
            ->first();

        if (!$domicile instanceof \stdClass) {
            return;
        }

        $connection->table($this->domicileHeaderTable())
            ->where('DE_TipoId', trim((string) $domicile->DP_TipoId))
            ->where('DE_Id', (int) $domicile->DP_Id)
            ->update([
                'DE_IndicadorImpreso' => 1,
                'DE_Estado' => 3,
            ]);

        $alreadyLogged = $connection->table($this->movementLogsTable())
            ->where('BI_TipoDocumento', trim((string) $domicile->DP_TipoId))
            ->where('BI_NumeroDocumento', (int) $domicile->DP_Id)
            ->where('BI_IdProceso', 19)
            ->exists();

        if (!$alreadyLogged) {
            $connection->table($this->movementLogsTable())->insert([
                'BI_TipoDocumento' => trim((string) $domicile->DP_TipoId),
                'BI_NumeroDocumento' => (int) $domicile->DP_Id,
                'BI_IdProceso' => 19,
                'BI_Fecha' => Carbon::now(),
                'BI_IdUsuario' => $this->serviceUserId(),
                'BI_Log' => 'Asesor anulo SODE',
            ]);
        }
    }

    private function cancelProductionTicketsIfPresent(ConnectionInterface $connection, OrderSiesaStateSnapshot $snapshot): void
    {
        $enterpriseOrderKey = $this->buildEnterpriseOrderKey($snapshot);
        if ($enterpriseOrderKey === '') {
            return;
        }

        try {
            $connection->table($this->productionTicketsTable())
                ->where('FC_Pedido', $enterpriseOrderKey)
                ->where('FC_Ot', 0)
                ->update([
                    'FC_IndicadorAnulado' => 1,
                    'FC_Ot' => -6,
                ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function buildEnterpriseOrderKey(OrderSiesaStateSnapshot $snapshot): string
    {
        $co = trim((string) ($snapshot->enterpriseOperationalCenter ?? ''));
        $type = trim((string) ($snapshot->enterpriseDocumentType ?? ''));
        $number = trim((string) ($snapshot->enterpriseDocumentNumber ?? ''));

        if ($co === '' || $type === '' || $number === '') {
            return '';
        }

        return strtoupper(sprintf(
            'EP%s%s%s',
            str_pad($co, 3, '0', STR_PAD_LEFT),
            str_pad($type, 3, ' ', STR_PAD_RIGHT),
            str_pad($number, 7, '0', STR_PAD_LEFT)
        ));
    }

    private function connectionFor(string $sourceConnection): ConnectionInterface
    {
        $configuredConnections = (array) $this->config->get('workerhub.orders.source_connections', []);
        $connection = (string) ($configuredConnections[$sourceConnection] ?? $sourceConnection);

        return $this->database->connection($connection);
    }

    private function enterpriseAccountingDocumentsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.enterprise_state.tables.accounting_documents', 'SiesaEnterprise.dbo.t350_co_docto_contable');
    }

    private function domicileOrderTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.domicile_orders', 'logistica.domicilios_pedido_encabezado');
    }

    private function domicileHeaderTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.domiciles', 'logistica.domicilios_encabezado');
    }

    private function movementLogsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.movement_logs', 'aplicacion.bitacora_movimientos');
    }

    private function productionTicketsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.production_tickets', 'produccion.fichos_comodisimos');
    }

    private function serviceUserId(): int
    {
        return (int) $this->config->get('workerhub.orders.legacy_state.service_user_id', 285);
    }
}
