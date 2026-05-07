<?php

namespace App\Services\Workers\Receipts;

use App\Contracts\ReceiptPreMigrationDataSourceInterface;
use App\Data\Receipts\ReceiptPreMigrationSnapshot;
use App\Exceptions\WorkerTaskProcessingException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use stdClass;

class SqlReceiptPreMigrationDataSource implements ReceiptPreMigrationDataSourceInterface
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config
    ) {
    }

    public function fetch(array $payload): ReceiptPreMigrationSnapshot
    {
        $reference = $this->resolveReference($payload);
        $connection = $this->connectionFor($reference['db_connection']);
        $receipt = $connection
            ->table($this->table())
            ->select([
                'RE_CentroOperativo',
                'RE_TipoDocumento',
                'RE_NumeroDocumento',
                'RE_ValorTotal',
                'RE_IndicadorAnulado',
                'RE_IndicadorSolicitudAnular',
                'RE_IndicadorMigrado',
                'RE_EstadoVerificadoExportacion',
            ])
            ->where('RE_CentroOperativo', $reference['operational_center'])
            ->where('RE_TipoDocumento', $reference['document_type'])
            ->where('RE_NumeroDocumento', $reference['document_number'])
            ->first();

        if (!$receipt instanceof stdClass) {
            throw new WorkerTaskProcessingException(
                'No se encontro el recibo para validar precondiciones de migracion.',
                ['reference' => $reference]
            );
        }

        $legalizedAmount = $this->callScalarFunction(
            $connection,
            $this->legalizedAmountFunction(),
            [
                $reference['operational_center'],
                $reference['document_type'],
                $reference['document_number'],
            ]
        );

        $isExpiredWithoutPayment = strtoupper((string) $this->callScalarFunction(
            $connection,
            $this->expiredWithoutPaymentFunction(),
            [
                $reference['operational_center'],
                $reference['document_type'],
                $reference['document_number'],
                0,
            ]
        )) === 'SI';

        return new ReceiptPreMigrationSnapshot(
            operationalCenter: (string) $receipt->RE_CentroOperativo,
            documentType: (string) $receipt->RE_TipoDocumento,
            documentNumber: (string) $receipt->RE_NumeroDocumento,
            totalAmount: (float) ($receipt->RE_ValorTotal ?? 0),
            legalizedAmount: (float) $legalizedAmount,
            isCancelled: (int) ($receipt->RE_IndicadorAnulado ?? 0) === 1,
            isCancellationRequested: (int) ($receipt->RE_IndicadorSolicitudAnular ?? 0) === 1,
            isWompiExpiredWithoutPayment: $isExpiredWithoutPayment,
            servicreditoPaymentCount: 0,
            isLegacyMigrated: (int) ($receipt->RE_IndicadorMigrado ?? 0) === 1,
            isLegacyExportVerified: (int) ($receipt->RE_EstadoVerificadoExportacion ?? 0) === 2,
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
                'El payload de receipt_migration esta incompleto para validar precondiciones. Configura: ' . implode(', ', $missing) . '.',
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

    private function connectionFor(string $sourceConnection): \Illuminate\Database\ConnectionInterface
    {
        $configuredConnections = (array) $this->config->get('workerhub.receipts.source_connections', []);
        $connection = (string) ($configuredConnections[$sourceConnection] ?? $sourceConnection);

        if (!$this->database->connection($connection)->getPdo()) {
            throw new WorkerTaskProcessingException(
                sprintf('No se pudo abrir la conexion de origen para validar recibos: %s.', $connection),
                ['source_connection' => $sourceConnection, 'resolved_connection' => $connection]
            );
        }

        return $this->database->connection($connection);
    }

    private function table(): string
    {
        return (string) $this->config->get('workerhub.receipts.pre_migration.table', 'pos.recibos_encabezado');
    }

    private function legalizedAmountFunction(): string
    {
        return (string) $this->config->get(
            'workerhub.receipts.pre_migration.functions.legalized_amount',
            'pos.fun_recibos_wompi_valor_legalizado'
        );
    }

    private function expiredWithoutPaymentFunction(): string
    {
        return (string) $this->config->get(
            'workerhub.receipts.pre_migration.functions.expired_without_payment',
            'pos.fun_recibos_wompi_es_sin_pago_vencido'
        );
    }

    /**
     * @param list<mixed> $arguments
     */
    private function callScalarFunction(
        \Illuminate\Database\ConnectionInterface $connection,
        string $functionName,
        array $arguments
    ): mixed {
        $placeholders = implode(', ', array_fill(0, count($arguments), '?'));
        $row = $connection->selectOne(sprintf('SELECT %s(%s) AS value', $functionName, $placeholders), $arguments);

        return $row?->value;
    }
}
