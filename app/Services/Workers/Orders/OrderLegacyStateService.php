<?php

namespace App\Services\Workers\Orders;

use App\Data\Orders\OrderSiesaStateSnapshot;
use App\Exceptions\WorkerTaskProcessingException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use stdClass;

class OrderLegacyStateService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config
    ) {
    }

    public function markMigrationStarted(array $payload): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->upsertHistory($payload, [
            'PH_IndicadorVerificado' => 0,
            'PH_FechaVerificado' => null,
            'PH_IdUsuarioVerifico' => null,
            'PH_IndicadorPedidoEstaMigrado' => 0,
            'PH_ConsecutivoDeMigracion' => 0,
            'PH_Text' => 'Pedido preparado para importacion en WorkerHub.',
        ]);
    }

    public function markMigrationFailed(array $payload): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->orderQuery($payload)->update([
            'PE_IndicadorMigrado' => 0,
            'PE_EstadoVerificadoExportacion' => 0,
        ]);
    }

    public function markMigrated(array $payload): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $timestamp = Carbon::now();

        $this->orderQuery($payload)->update([
            'PE_IndicadorMigrado' => 1,
            'PE_FechaMigracion' => $timestamp,
            'PE_CodigoUsuarioMigro' => $this->serviceUserId(),
            'PE_ConsecutivoDeMigracion' => 0,
            'PE_EstadoVerificadoExportacion' => 1,
        ]);
    }

    public function markVerified(array $payload, OrderSiesaStateSnapshot $snapshot, float $legacyNetTotal): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $timestamp = Carbon::now();

        $this->orderQuery($payload)->update([
            'PE_IndicadorMigrado' => 1,
            'PE_FechaMigracion' => $timestamp,
            'PE_CodigoUsuarioMigro' => $this->serviceUserId(),
            'PE_ConsecutivoDeMigracion' => 0,
            'PE_EstadoVerificadoExportacion' => 2,
            'PE_FechaVerificacionExportacion' => $timestamp,
            'PE_IndicadorImpreso' => 1,
            'PE_rowid_t430_pedido' => $snapshot->rowId,
        ]);

        $reference = $this->resolveReference($payload);
        $enterpriseNetTotal = $snapshot->netTotal ?? 0.0;

        $this->upsertHistory($payload, [
            'PH_IndicadorVerificado' => 1,
            'PH_FechaVerificado' => $timestamp,
            'PH_IdUsuarioVerifico' => $this->serviceUserId(),
            'PH_IndicadorPedidoEstaMigrado' => 1,
            'PH_ConsecutivoDeMigracion' => 0,
            'PH_Text' => sprintf(
                'Pedido EP%s%s%s correctamente migrado. Valor sistema UNO %s Valor sistema Pedido %s',
                $reference['operational_center'],
                $reference['document_type'],
                str_pad($reference['document_number'], 6, '0', STR_PAD_LEFT),
                number_format($enterpriseNetTotal, 2, '.', ''),
                number_format($legacyNetTotal, 2, '.', '')
            ),
        ]);

        $this->markChainOrderImported($payload);
    }

    public function markDetectedInSiesa(array $payload, OrderSiesaStateSnapshot $snapshot): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $timestamp = Carbon::now();

        $this->orderQuery($payload)->update([
            'PE_IndicadorMigrado' => 1,
            'PE_FechaMigracion' => $timestamp,
            'PE_CodigoUsuarioMigro' => $this->serviceUserId(),
            'PE_ConsecutivoDeMigracion' => 0,
            'PE_EstadoVerificadoExportacion' => 2,
            'PE_FechaVerificacionExportacion' => $timestamp,
            'PE_IndicadorImpreso' => 1,
            'PE_rowid_t430_pedido' => $snapshot->rowId,
        ]);

        $this->upsertHistory($payload, [
            'PH_IndicadorVerificado' => 1,
            'PH_FechaVerificado' => $timestamp,
            'PH_IdUsuarioVerifico' => $this->serviceUserId(),
            'PH_IndicadorPedidoEstaMigrado' => 1,
            'PH_ConsecutivoDeMigracion' => 0,
            'PH_Text' => sprintf(
                'Pedido %s-%s-%s correctamente migrado en Siesa.',
                trim((string) ($payload['operational_center'] ?? '')),
                trim((string) ($payload['document_type'] ?? '')),
                trim((string) ($payload['document_number'] ?? ''))
            ),
        ]);

        $this->markChainOrderImported($payload);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function upsertHistory(array $payload, array $attributes): void
    {
        $reference = $this->resolveReference($payload);
        $timestamp = Carbon::now();

        $defaults = array_merge([
            'PH_IdUsuario' => $this->serviceUserId(),
            'PH_Fecha' => $timestamp,
        ], $attributes);

        $this->connectionFor($reference['db_connection'])
            ->table($this->historyTable())
            ->updateOrInsert([
                'PH_CentroOperativo' => $reference['operational_center'],
                'PH_TipoDocumento' => $reference['document_type'],
                'PH_Numero' => $reference['document_number'],
            ], $defaults);
    }

    private function markChainOrderImported(array $payload): void
    {
        $reference = $this->resolveReference($payload);

        $this->connectionFor($reference['db_connection'])
            ->table($this->chainOrdersTable())
            ->where('rowid_pedido_sgc', function ($query) use ($reference) {
                $query->from($this->ordersTable())
                    ->select('PE_RowId')
                    ->where('PE_CentroOperativo', $reference['operational_center'])
                    ->where('PE_TipoDocumento', $reference['document_type'])
                    ->where('PE_NumeroDocumento', $reference['document_number']);
            })
            ->update(['estadoImportacion' => 3]);
    }

    private function orderQuery(array $payload): \Illuminate\Database\Query\Builder
    {
        $reference = $this->resolveReference($payload);

        return $this->connectionFor($reference['db_connection'])
            ->table($this->ordersTable())
            ->where('PE_CentroOperativo', $reference['operational_center'])
            ->where('PE_TipoDocumento', $reference['document_type'])
            ->where('PE_NumeroDocumento', $reference['document_number']);
    }

    public function updateEnterpriseRowId(array $payload, OrderSiesaStateSnapshot $snapshot): void
    {
        if (!$this->isEnabled() || $snapshot->rowId === null) {
            return;
        }

        $this->orderQuery($payload)->update([
            'PE_rowid_t430_pedido' => $snapshot->rowId,
        ]);
    }

    public function computeLegacyNetTotal(array $payload): float
    {
        $reference = $this->resolveReference($payload);
        $rows = $this->connectionFor($reference['db_connection'])
            ->table($this->orderDetailsTable())
            ->select(['PD_CodigoMotivo', 'PD_ValorNeto'])
            ->where('PD_CentroOperativo', $reference['operational_center'])
            ->where('PD_TipoDocumento', $reference['document_type'])
            ->where('PD_NumeroDocumento', $reference['document_number'])
            ->get();

        $total = 0.0;

        foreach ($rows as $row) {
            if (!$row instanceof stdClass) {
                continue;
            }

            if (trim((string) ($row->PD_CodigoMotivo ?? '')) === '34') {
                continue;
            }

            $total += (float) ($row->PD_ValorNeto ?? 0);
        }

        return $total;
    }

    public function verificationThreshold(): float
    {
        return (float) $this->config->get('workerhub.orders.legacy_state.verification_threshold', 1000);
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
                'El payload de order_migration esta incompleto para sincronizar indicadores legacy. Configura: ' . implode(', ', $missing) . '.',
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
                sprintf('No se pudo abrir la conexion de origen para sincronizar indicadores legacy del pedido: %s.', $connection),
                ['source_connection' => $sourceConnection, 'resolved_connection' => $connection]
            );
        }

        return $this->database->connection($connection);
    }

    private function ordersTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.orders', 'pos.pedidos_encabezado');
    }

    private function historyTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.history', 'pos.pedidos_historia_migracion');
    }

    private function chainOrdersTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.chain_orders', 'ventas.pedidos_cadenas');
    }

    private function orderDetailsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.order_details', 'pos.pedidos_detalle');
    }

    private function serviceUserId(): int
    {
        return (int) $this->config->get('workerhub.orders.legacy_state.service_user_id', 285);
    }

    private function isEnabled(): bool
    {
        return (bool) $this->config->get('workerhub.orders.legacy_state.enabled', true);
    }
}
