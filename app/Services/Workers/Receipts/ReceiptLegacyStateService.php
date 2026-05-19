<?php

namespace App\Services\Workers\Receipts;

use App\Exceptions\WorkerTaskProcessingException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

class ReceiptLegacyStateService
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
            'RH_IndicadorVerificado' => 0,
            'RH_FechaVerificado' => null,
            'RH_IdUsuarioVerifico' => null,
            'RH_IndicadorPedidoEstaMigrado' => 0,
            'RH_ConsecutivoDeMigracion' => 0,
        ]);
    }

    public function markMigrationFailed(array $payload): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->receiptQuery($payload)
            ->where(function ($query): void {
                $query->whereNull('RE_EstadoVerificadoExportacion')
                    ->orWhere('RE_EstadoVerificadoExportacion', '<>', 2);
            })
            ->update([
                'RE_IndicadorMigrado' => 0,
            ]);
    }

    public function markMigrated(array $payload): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $timestamp = Carbon::now();

        $this->receiptQuery($payload)->update([
            'RE_IndicadorMigrado' => 1,
            'RE_FechaMigracion' => $timestamp,
            'RE_CodigoUsuarioMigro' => $this->serviceUserId(),
            'RE_EstadoVerificadoExportacion' => 1,
        ]);

        $this->upsertHistory($payload, [
            'RH_IndicadorVerificado' => 0,
            'RH_FechaVerificado' => null,
            'RH_IdUsuarioVerifico' => null,
            'RH_IndicadorPedidoEstaMigrado' => 0,
            'RH_ConsecutivoDeMigracion' => 0,
        ]);
    }

    public function markDetectedInSiesa(array $payload): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $timestamp = Carbon::now();

        $this->receiptQuery($payload)->update([
            'RE_IndicadorMigrado' => 1,
            'RE_IndicadorAprobadoPorCartera' => 1,
            'RE_FechaMigracion' => $timestamp,
            'RE_CodigoUsuarioMigro' => $this->serviceUserId(),
            'RE_EstadoVerificadoExportacion' => 2,
            'RE_FechaVerificacionExportacion' => $timestamp,
        ]);

        $this->upsertHistory($payload, [
            'RH_IndicadorVerificado' => 1,
            'RH_FechaVerificado' => $timestamp,
            'RH_IdUsuarioVerifico' => $this->serviceUserId(),
            'RH_IndicadorPedidoEstaMigrado' => 1,
            'RH_ConsecutivoDeMigracion' => 0,
        ]);
    }

    public function markCancellationRequested(array $payload): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->receiptQuery($payload)->update([
            'RE_IndicadorSolicitudAnular' => 1,
        ]);
    }

    public function markCancelled(array $payload, string $comments = 'Anulado desde WorkerHub'): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $timestamp = Carbon::now();

        $this->receiptQuery($payload)->update([
            'RE_IndicadorMigrado' => 1,
            'RE_IndicadorAprobadoPorCartera' => 1,
            'RE_IndicadorSolicitudAnular' => 1,
            'RE_IndicadorAnulado' => 1,
            'RE_FechaAnulado' => $timestamp,
            'RE_CodigoUsuarioAnulo' => $this->serviceUserId(),
            'RE_EstadoVerificadoExportacion' => 2,
            'RE_FechaVerificacionExportacion' => $timestamp,
            'RE_SolicitudAnularComentarios' => $comments,
        ]);

        $this->upsertHistory($payload, [
            'RH_IndicadorVerificado' => 1,
            'RH_FechaVerificado' => $timestamp,
            'RH_IdUsuarioVerifico' => $this->serviceUserId(),
            'RH_IndicadorPedidoEstaMigrado' => 1,
            'RH_ConsecutivoDeMigracion' => 0,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function upsertHistory(array $payload, array $attributes): void
    {
        $reference = $this->resolveReference($payload);
        $timestamp = Carbon::now();

        $defaults = array_merge([
            'RH_IdUsuario' => $this->serviceUserId(),
            'RH_Fecha' => $timestamp,
        ], $attributes);

        $this->connectionFor($reference['db_connection'])
            ->table($this->historyTable())
            ->updateOrInsert([
                'RH_CentroOperativo' => $reference['operational_center'],
                'RH_TipoDocumento' => $reference['document_type'],
                'RH_Numero' => $reference['document_number'],
            ], $defaults);
    }

    private function receiptQuery(array $payload): \Illuminate\Database\Query\Builder
    {
        $reference = $this->resolveReference($payload);

        return $this->connectionFor($reference['db_connection'])
            ->table($this->table())
            ->where('RE_CentroOperativo', $reference['operational_center'])
            ->where('RE_TipoDocumento', $reference['document_type'])
            ->where('RE_NumeroDocumento', $reference['document_number']);
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
                'El payload de receipt_migration esta incompleto para sincronizar indicadores legacy. Configura: ' . implode(', ', $missing) . '.',
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
                sprintf('No se pudo abrir la conexion de origen para sincronizar indicadores legacy del recibo: %s.', $connection),
                ['source_connection' => $sourceConnection, 'resolved_connection' => $connection]
            );
        }

        return $this->database->connection($connection);
    }

    private function table(): string
    {
        return (string) $this->config->get('workerhub.receipts.legacy_state.table', 'pos.recibos_encabezado');
    }

    private function historyTable(): string
    {
        return (string) $this->config->get('workerhub.receipts.legacy_state.history_table', 'pos.recibos_historia_migracion');
    }

    private function serviceUserId(): int
    {
        return (int) $this->config->get('workerhub.receipts.legacy_state.service_user_id', 285);
    }

    private function isEnabled(): bool
    {
        return (bool) $this->config->get('workerhub.receipts.legacy_state.enabled', true);
    }
}
