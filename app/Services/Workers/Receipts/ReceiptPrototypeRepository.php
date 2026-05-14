<?php

namespace App\Services\Workers\Receipts;

use App\Exceptions\WorkerTaskProcessingException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use stdClass;

class ReceiptPrototypeRepository
{
    /**
     * @var array<string, string>
     */
    private array $enterpriseDocumentTypeCache = [];

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
            ->where('RE_CentroOperativo', $reference['operational_center'])
            ->where('RE_TipoDocumento', $reference['document_type'])
            ->where('RE_NumeroDocumento', $reference['document_number'])
            ->first();

        if (!$record instanceof stdClass) {
            throw new WorkerTaskProcessingException(
                'No se encontro el recibo en la vista prototipo de encabezado.',
                ['reference' => $reference]
            );
        }

        return $this->normalizePrototypeDocumentType($record, $reference['db_connection']);
    }

    /**
     * Completa el payload de migracion del recibo usando el rowid como fuente de verdad.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function hydratePayloadFromReceiptId(array $payload): array
    {
        $dbConnection = trim((string) ($payload['db_connection'] ?? ''));
        $receiptId = $payload['receipt_id'] ?? null;

        if ($dbConnection === '' || !is_numeric($receiptId)) {
            throw new WorkerTaskProcessingException(
                'El payload de receipt_migration requiere db_connection y receipt_id para resolver el recibo.',
                ['payload' => $payload]
            );
        }

        $record = $this->connectionFor($dbConnection)
            ->table($this->sourceReceiptTable())
            ->select([
                'RE_rowid',
                'RE_CentroOperativo',
                'RE_TipoDocumento',
                'RE_NumeroDocumento',
                'RE_CodigoTercero',
                'RE_CodigoSucursal',
            ])
            ->where('RE_rowid', (int) $receiptId)
            ->first();

        if (!$record instanceof stdClass) {
            throw new WorkerTaskProcessingException(
                'No se encontro el recibo origen para el rowid enviado a WorkerHub.',
                ['payload' => $payload, 'receipt_id' => (int) $receiptId]
            );
        }

        $operationalCenter = trim((string) ($record->RE_CentroOperativo ?? ''));
        $documentType = trim((string) ($record->RE_TipoDocumento ?? ''));
        $documentNumber = trim((string) ($record->RE_NumeroDocumento ?? ''));

        return array_merge($payload, [
            'receipt_id' => (int) ($record->RE_rowid ?? $receiptId),
            'document_id' => sprintf('%s-%s-%s', $operationalCenter, $documentType, $documentNumber),
            'operational_center' => $operationalCenter,
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'client_code' => trim((string) ($record->RE_CodigoTercero ?? ($payload['client_code'] ?? ''))),
            'client_branch' => trim((string) ($record->RE_CodigoSucursal ?? ($payload['client_branch'] ?? ''))),
        ]);
    }

    /**
     * @return list<stdClass>
     */
    public function findPayments(array $payload): array
    {
        $reference = $this->resolveReference($payload);

        $records = $this->connectionFor($reference['db_connection'])
            ->table($this->paymentsView())
            ->where('RE_CentroOperativo', $reference['operational_center'])
            ->where('RE_TipoDocumento', $reference['document_type'])
            ->where('RE_NumeroDocumento', $reference['document_number'])
            ->orderBy('RD_NumeroDeRegistro')
            ->get()
            ->all();

        if ($records === []) {
            throw new WorkerTaskProcessingException(
                'No se encontraron medios de pago del recibo en la vista prototipo.',
                ['reference' => $reference]
            );
        }

        return array_map(
            fn (stdClass $record): stdClass => $this->normalizePrototypeDocumentType($record, $reference['db_connection']),
            $records
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
                'El payload de receipt_migration esta incompleto. Configura: ' . implode(', ', $missing) . '.',
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
                sprintf('No se pudo abrir la conexion de origen para recibos: %s.', $connection),
                ['source_connection' => $sourceConnection, 'resolved_connection' => $connection]
            );
        }

        return $this->database->connection($connection);
    }

    private function headerView(): string
    {
        return (string) $this->config->get('workerhub.receipts.views.header');
    }

    private function paymentsView(): string
    {
        return (string) $this->config->get('workerhub.receipts.views.payments');
    }

    private function sourceReceiptTable(): string
    {
        return (string) $this->config->get('workerhub.receipts.pre_migration.table', 'pos.recibos_encabezado');
    }

    private function normalizePrototypeDocumentType(stdClass $record, string $sourceConnection): stdClass
    {
        $sourceDocumentType = strtoupper(trim((string) ($record->RE_TipoDocumento ?? $record->F350_ID_TIPO_DOCTO ?? '')));
        $overrides = (array) $this->config->get('workerhub.receipts.prototype.document_type_overrides', []);
        $targetDocumentType = trim((string) ($overrides[$sourceDocumentType] ?? ''));

        if ($targetDocumentType === '') {
            $targetDocumentType = $this->resolveLegacyEnterpriseDocumentType($record, $sourceConnection);
        }

        if ($targetDocumentType !== '') {
            $record->F350_ID_TIPO_DOCTO = $targetDocumentType;
        }

        return $record;
    }

    private function resolveLegacyEnterpriseDocumentType(stdClass $record, string $sourceConnection): string
    {
        $enterpriseCo = trim((string) ($record->F350_ID_CO ?? $record->RE_CentroOperativo ?? ''));
        $storeCode = trim((string) ($record->RE_CodigoSalaDeVentas ?? ''));

        if ($enterpriseCo === '' && $storeCode === '') {
            return '';
        }

        $cacheKey = $sourceConnection . '|' . $enterpriseCo . '|' . $storeCode;
        if (array_key_exists($cacheKey, $this->enterpriseDocumentTypeCache)) {
            return $this->enterpriseDocumentTypeCache[$cacheKey];
        }

        $activationTable = (string) $this->config->get(
            'workerhub.receipts.prototype.activation_table',
            'contabilidad.er_salas_fechas_activacion'
        );
        $salesTable = (string) $this->config->get(
            'workerhub.receipts.prototype.sales_table',
            'pos.salas_informacion_adicional'
        );

        $row = $this->connectionFor($sourceConnection)
            ->table($activationTable . ' as activation')
            ->leftJoin($salesTable . ' as sale', 'activation.FC_co', '=', 'sale.SA_CentroOperativoEnterprise')
            ->when($storeCode !== '', fn ($query) => $query->where('sale.SA_Id', $storeCode))
            ->when($storeCode === '' && $enterpriseCo !== '', fn ($query) => $query->where('activation.FC_co', $enterpriseCo))
            ->select([
                'activation.FC_lapso_apertura',
                'sale.SA_PedidoPrefijo',
            ])
            ->first();

        $targetDocumentType = '';
        if ($row instanceof stdClass) {
            $activationLapso = (int) ($row->FC_lapso_apertura ?? 0);
            $threshold = (int) $this->config->get('workerhub.receipts.prototype.enterprise_activation_lapso_threshold', 202011);

            if ($activationLapso >= $threshold) {
                $targetDocumentType = (string) $this->config->get('workerhub.receipts.prototype.enterprise_receipt_document_type', 'RS');
            } else {
                $prefix = strtoupper(trim((string) ($row->SA_PedidoPrefijo ?? '')));
                $targetDocumentType = $prefix !== '' ? 'R' . $prefix : '';
            }
        }

        return $this->enterpriseDocumentTypeCache[$cacheKey] = $targetDocumentType;
    }
}
