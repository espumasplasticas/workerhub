<?php

namespace App\Services\Workers\Invoices;

use App\Data\Invoices\InvoiceSiesaStateSnapshot;
use App\Exceptions\WorkerTaskProcessingException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

class InvoiceLegacyStateService
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
            'FH_IndicadorVerificado' => 0,
            'FH_FechaVerificado' => null,
            'FH_IdUsuarioVerifico' => null,
            'FH_IndicadorPedidoEstaMigrado' => 0,
            'FH_ConsecutivoDeMigracion' => 0,
        ]);
    }

    public function markMigrationFailed(array $payload): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->invoiceQuery($payload)->update([
            'FE_IndicadorMigrado' => 0,
            'FE_EstadoVerificadoExportacion' => 0,
        ]);
    }

    public function markMigrated(array $payload): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $timestamp = Carbon::now();

        $this->invoiceQuery($payload)->update([
            'FE_IndicadorMigrado' => 1,
            'FE_IndicadorAprobadoPorCartera' => 1,
            'FE_FechaMigracion' => $timestamp,
            'FE_CodigoUsuarioMigro' => $this->serviceUserId(),
            'FE_ConsecutivoDeMigracion' => 0,
            'FE_EstadoVerificadoExportacion' => 1,
        ]);

        $this->upsertHistory($payload, [
            'FH_IndicadorVerificado' => 0,
            'FH_FechaVerificado' => null,
            'FH_IdUsuarioVerifico' => null,
            'FH_IndicadorPedidoEstaMigrado' => 0,
            'FH_ConsecutivoDeMigracion' => 0,
        ]);
    }

    public function markVerified(array $payload, InvoiceSiesaStateSnapshot $snapshot, float $legacyInvoiceTotal): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $timestamp = Carbon::now();
        $this->invoiceQuery($payload)->update([
            'FE_IndicadorMigrado' => 1,
            'FE_IndicadorAprobadoPorCartera' => 1,
            'FE_FechaMigracion' => $timestamp,
            'FE_CodigoUsuarioMigro' => $this->serviceUserId(),
            'FE_ConsecutivoDeMigracion' => 0,
            'FE_EstadoVerificadoExportacion' => 2,
            'FE_FechaVerificacionExportacion' => $timestamp,
            'FE_rowid_t350_factura' => $snapshot->rowId,
        ]);

        $enterpriseInvoiceTotal = (float) ($snapshot->netTotal ?? 0);

        $this->upsertHistory($payload, [
            'FH_IndicadorVerificado' => 1,
            'FH_FechaVerificado' => $timestamp,
            'FH_IdUsuarioVerifico' => $this->serviceUserId(),
            'FH_IndicadorPedidoEstaMigrado' => 1,
            'FH_ConsecutivoDeMigracion' => 0,
            'FH_Text' => sprintf(
                'Factura correctamente migrada. Valor enterprise %s Valor POS %s',
                number_format($enterpriseInvoiceTotal, 2, '.', ''),
                number_format($legacyInvoiceTotal, 2, '.', '')
            ),
        ]);
    }

    public function markDetectedInSiesa(array $payload, InvoiceSiesaStateSnapshot $snapshot): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $timestamp = Carbon::now();

        $this->invoiceQuery($payload)->update([
            'FE_IndicadorMigrado' => 1,
            'FE_IndicadorAprobadoPorCartera' => 1,
            'FE_FechaMigracion' => $timestamp,
            'FE_CodigoUsuarioMigro' => $this->serviceUserId(),
            'FE_ConsecutivoDeMigracion' => 0,
            'FE_EstadoVerificadoExportacion' => 2,
            'FE_FechaVerificacionExportacion' => $timestamp,
            'FE_rowid_t350_factura' => $snapshot->rowId,
        ]);

        $this->upsertHistory($payload, [
            'FH_IndicadorVerificado' => 1,
            'FH_FechaVerificado' => $timestamp,
            'FH_IdUsuarioVerifico' => $this->serviceUserId(),
            'FH_IndicadorPedidoEstaMigrado' => 1,
            'FH_ConsecutivoDeMigracion' => 0,
            'FH_Text' => sprintf(
                'Factura %s-%s-%s correctamente migrada en Siesa.',
                trim((string) ($payload['operational_center'] ?? '')),
                trim((string) ($payload['document_type'] ?? '')),
                trim((string) ($payload['document_number'] ?? ''))
            ),
        ]);
    }

    public function verificationThreshold(): float
    {
        return (float) $this->config->get('workerhub.invoices.legacy_state.verification_threshold', 50);
    }

    private function invoiceQuery(array $payload): \Illuminate\Database\Query\Builder
    {
        $reference = $this->resolveReference($payload);

        return $this->connectionFor($reference['db_connection'])
            ->table($this->table())
            ->where('FE_CentroOperativo', $reference['operational_center'])
            ->where('FE_TipoDocumento', $reference['document_type'])
            ->where('FE_NumeroDocumento', $reference['document_number']);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function upsertHistory(array $payload, array $attributes): void
    {
        $reference = $this->resolveReference($payload);
        $timestamp = Carbon::now();

        $defaults = array_merge([
            'FH_IdUsuario' => $this->serviceUserId(),
            'FH_Fecha' => $timestamp,
        ], $attributes);

        $this->connectionFor($reference['db_connection'])
            ->table($this->historyTable())
            ->updateOrInsert([
                'FH_CentroOperativo' => $reference['operational_center'],
                'FH_TipoDocumento' => $reference['document_type'],
                'FH_Numero' => $reference['document_number'],
            ], $defaults);
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
                'El payload de invoice_migration esta incompleto para sincronizar indicadores legacy. Configura: ' . implode(', ', $missing) . '.',
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
                sprintf('No se pudo abrir la conexion de origen para sincronizar indicadores legacy de la factura: %s.', $connection),
                ['source_connection' => $sourceConnection, 'resolved_connection' => $connection]
            );
        }

        return $this->database->connection($connection);
    }

    private function table(): string
    {
        return (string) $this->config->get('workerhub.invoices.tables.invoices', 'pos.facturas_encabezado');
    }

    private function historyTable(): string
    {
        return (string) $this->config->get('workerhub.invoices.tables.history', 'pos.facturas_historia_migracion');
    }

    private function serviceUserId(): int
    {
        return (int) $this->config->get('workerhub.invoices.legacy_state.service_user_id', 285);
    }

    private function isEnabled(): bool
    {
        return (bool) $this->config->get('workerhub.invoices.legacy_state.enabled', true);
    }
}
