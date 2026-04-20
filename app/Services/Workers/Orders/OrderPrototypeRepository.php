<?php

namespace App\Services\Workers\Orders;

use App\Exceptions\WorkerTaskProcessingException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use stdClass;

class OrderPrototypeRepository
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config
    ) {
    }

    public function findHeader(array $payload): stdClass
    {
        $reference = $this->resolveReference($payload);

        $record = $this->connectionFor($reference['db_connection'])
            ->table($this->headerView())
            ->where('PE_CentroOperativo', $reference['operational_center'])
            ->where('PE_TipoDocumento', $reference['document_type'])
            ->where('PE_NumeroDocumento', $reference['document_number'])
            ->first();

        if (!$record instanceof stdClass) {
            throw new WorkerTaskProcessingException(
                'No se encontro el pedido en la vista prototipo de encabezado.',
                ['reference' => $reference]
            );
        }

        return $record;
    }

    /**
     * @return list<stdClass>
     */
    public function findDetails(array $payload): array
    {
        $reference = $this->resolveReference($payload);
        $connection = $this->connectionFor($reference['db_connection']);
        $this->prepareDetailItems($connection, $reference);

        $records = $connection->select(
            sprintf(
                "SELECT *, ROW_NUMBER() OVER (ORDER BY f431_id_co) AS f431_nro_registro
                FROM %s
                WHERE PD_CentroOperativo = ?
                  AND PD_TipoDocumento = ?
                  AND PD_NumeroDocumento = ?",
                $this->detailView()
            ),
            [
                $reference['operational_center'],
                $reference['document_type'],
                $reference['document_number'],
            ]
        );

        if ($records === []) {
            throw new WorkerTaskProcessingException(
                'No se encontraron lineas del pedido en la vista prototipo de detalle.',
                ['reference' => $reference]
            );
        }

        return array_values(array_filter($records, static fn ($record) => $record instanceof stdClass));
    }

    public function findOrderRecord(array $payload): stdClass
    {
        $reference = $this->resolveReference($payload);

        $record = $this->connectionFor($reference['db_connection'])
            ->table($this->ordersTable())
            ->where('PE_CentroOperativo', $reference['operational_center'])
            ->where('PE_TipoDocumento', $reference['document_type'])
            ->where('PE_NumeroDocumento', $reference['document_number'])
            ->first();

        if (!$record instanceof stdClass) {
            throw new WorkerTaskProcessingException(
                'No se encontro el pedido en la tabla legacy POS.',
                ['reference' => $reference]
            );
        }

        return $record;
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
                'El payload de order_migration esta incompleto. Configura: ' . implode(', ', $missing) . '.',
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

    /**
     * @param array{operational_center: string, document_type: string, document_number: string} $reference
     */
    private function prepareDetailItems(ConnectionInterface $connection, array $reference): void
    {
        $statement = sprintf(
            'EXEC %s ?, ?, ?, ?',
            $this->detailPrepareProcedure()
        );

        $connection->statement($statement, [
            $reference['operational_center'],
            $reference['document_type'],
            $reference['document_number'],
            (string) $this->config->get('workerhub.orders.detail.price_list', 'C37'),
        ]);
    }

    private function connectionFor(string $sourceConnection): ConnectionInterface
    {
        $configuredConnections = (array) $this->config->get('workerhub.orders.source_connections', []);
        $connection = (string) ($configuredConnections[$sourceConnection] ?? $sourceConnection);

        if (!$this->database->connection($connection)->getPdo()) {
            throw new WorkerTaskProcessingException(
                sprintf('No se pudo abrir la conexion de origen para pedidos: %s.', $connection),
                ['source_connection' => $sourceConnection, 'resolved_connection' => $connection]
            );
        }

        return $this->database->connection($connection);
    }

    private function headerView(): string
    {
        return (string) $this->config->get('workerhub.orders.views.header');
    }

    private function detailView(): string
    {
        return (string) $this->config->get('workerhub.orders.views.detail');
    }

    private function detailPrepareProcedure(): string
    {
        return (string) $this->config->get('workerhub.orders.detail.prepare_procedure');
    }

    private function ordersTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.orders', 'pos.pedidos_encabezado');
    }
}
