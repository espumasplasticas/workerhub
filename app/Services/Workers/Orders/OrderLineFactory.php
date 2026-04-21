<?php

namespace App\Services\Workers\Orders;

use App\Exceptions\WorkerTaskProcessingException;
use Epsalibrary\Legacy\Adapters\SalesOrders\LegacySalesOrderHeaderAdapter;
use Epsalibrary\Legacy\Adapters\SalesOrders\LegacySalesOrderLineAdapter;
use Epsalibrary\Siesa\Connectors\PrototipoPedidoDetalle;
use Epsalibrary\Siesa\Connectors\PrototipoPedidoEncabezado;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use stdClass;

class OrderLineFactory
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
        private readonly OrderHeaderReferenceResolver $headerReferenceResolver
    ) {
    }

    /**
     * @param list<stdClass> $detailRows
     * @return list<string>
     */
    public function build(array $payload, stdClass $header, array $detailRows): array
    {
        $connection = $this->connectionFor((string) ($payload['db_connection'] ?? ''));
        $mutatedHeader = $this->mutateHeader($connection, $payload, $header);
        $headerConnector = $this->hydrate(new PrototipoPedidoEncabezado(), $mutatedHeader);

        $lines = [
            (new LegacySalesOrderHeaderAdapter($headerConnector))->toLine(),
        ];

        foreach ($detailRows as $detailRow) {
            $mutatedRow = $this->mutateDetail($connection, $mutatedHeader, $detailRow);
            $detailConnector = $this->hydrate(new PrototipoPedidoDetalle(), $mutatedRow);
            $adapter = new LegacySalesOrderLineAdapter($detailConnector);

            $lines[] = $adapter->toLine();

            if ((int) ($mutatedHeader->PE_IndicadorObsequio ?? 0) === 1) {
                continue;
            }

            $discountOrder = 1;

            if ((float) ($detailConnector->PorcentajeDescuento ?? 0) > 0 || (float) ($detailConnector->PD_DescuentoValor ?? 0) > 0) {
                $lines[] = $adapter->toDiscountLine();
                $discountOrder = 2;
            }

            if ((float) ($detailConnector->PD_TasaDescuento2 ?? 0) > 0) {
                $lines[] = $adapter->toManualDiscountLine($discountOrder, (float) $detailConnector->PD_TasaDescuento2, 0);
            } elseif ((float) ($detailConnector->PD_DescuentoValor2 ?? 0) > 0) {
                $lines[] = $adapter->toManualDiscountLine($discountOrder, 0, (float) $detailConnector->PD_DescuentoValor2);
            }
        }

        return $lines;
    }

    private function mutateHeader(ConnectionInterface $connection, array $payload, stdClass $header): stdClass
    {
        $header = clone $header;
        $header->f430_num_docto_referencia = trim((string) ($header->f430_num_docto_referencia ?? $payload['purchase_order'] ?? ''));
        $header->f430_referencia = trim((string) ($header->f430_referencia ?? $payload['load_order'] ?? ''));
        $orderRecord = $this->loadOrderReferenceRecord($connection, $header);
        $resolvedReferences = $this->headerReferenceResolver->resolve($header, $orderRecord);

        $header->f430_num_docto_referencia = $resolvedReferences['reference_document_number'];
        $header->f430_referencia = $resolvedReferences['reference'];
        $header->order_source_branch = trim((string) ($header->PE_CodigoSucursal ?? $header->f430_id_sucursal_fact ?? ''));
        $documentType = trim((string) ($header->PE_TipoDocumento ?? ''));

        if ($documentType !== '' && $this->activationPeriodFor($connection, $documentType) >= 202011) {
            $header->f430_id_co = $documentType;
            $header->f430_id_co_fact = $documentType;
        }

        $sala = trim((string) ($header->PE_CodigoSalaDeVentas ?? ''));

        if (in_array($sala, ['0240', '0242'], true)) {
            $header->f430_id_tipo_cli_fact = '0010';
        } elseif ($sala === '0250') {
            $header->f430_id_tipo_cli_fact = '0012';
        }

        $header->f430_notas = $this->buildAdditionalDetail($header);

        if ((int) ($header->PE_IndicadorObsequio ?? 0) === 1) {
            $customerId = trim((string) ($header->f430_id_tercero_fact ?? ''));
            $sellerId = trim((string) ($header->f430_id_tercero_vendedor ?? ''));
            $prefix = trim((string) ($header->PE_CodigoMotivo ?? '')) === 'ME'
                ? 'PEDIDO DE MUESTRA'
                : 'PEDIDO DE OBSEQUIO NO PRODUCIR';
            $header->f430_notas = trim(sprintf('%s %s CED[%s]ASESOR[%s]', $prefix, $header->f430_notas, $customerId, $sellerId));
            $header->f430_ind_estado = 1;
        }

        $this->applyChainOverrides($connection, $header);
        $this->applyBackorderOverride($connection, $header);

        if ((int) ($header->PE_IndicadorSolicitadoManual ?? 0) === 1 || (int) ($header->PE_IndicadorAprobadoPorCartera ?? 0) === 1) {
            $header->f430_ind_estado = 0;
        }

        return $header;
    }

    private function loadOrderReferenceRecord(ConnectionInterface $connection, stdClass $header): ?stdClass
    {
        $existingReferenceDocumentNumber = trim((string) ($header->f430_num_docto_referencia ?? ''));
        $existingReference = trim((string) ($header->f430_referencia ?? ''));
        $purchaseOrder = trim((string) ($header->PE_OrdenDeCompra ?? ''));
        $loadOrder = trim((string) ($header->PE_OrdenDeCargue ?? ''));

        if ($existingReferenceDocumentNumber !== '' && $existingReference !== '') {
            return null;
        }

        if ($purchaseOrder !== '' && $loadOrder !== '') {
            return null;
        }

        $operationalCenter = trim((string) ($header->PE_CentroOperativo ?? ''));
        $documentType = trim((string) ($header->PE_TipoDocumento ?? ''));
        $documentNumber = trim((string) ($header->PE_NumeroDocumento ?? ''));

        if ($operationalCenter === '' || $documentType === '' || $documentNumber === '') {
            return null;
        }

        return $connection->table($this->ordersTable())
            ->select(['PE_OrdenDeCompra', 'PE_OrdenDeCargue'])
            ->where('PE_CentroOperativo', $operationalCenter)
            ->where('PE_TipoDocumento', $documentType)
            ->where('PE_NumeroDocumento', $documentNumber)
            ->first();
    }

    private function mutateDetail(ConnectionInterface $connection, stdClass $header, stdClass $detailRow): stdClass
    {
        $detail = clone $detailRow;
        $itemId = trim((string) ($detail->f431_id_item ?? $detail->PD_CodigoItem ?? ''));
        $itemReference = trim((string) ($detail->f431_referencia_item ?? $detail->PD_Referencia ?? ''));
        $item = $this->findItem($connection, $itemId, $itemReference);

        if (!$item instanceof stdClass) {
            throw new WorkerTaskProcessingException(
                sprintf('No se encontro el item %s del pedido en Siesa.', $itemId !== '' ? $itemId : $itemReference),
                ['item_id' => $itemId, 'item_reference' => $itemReference]
            );
        }

        $kit = trim((string) ($detail->PD_Kit ?? ''));
        $manualRequest = (int) ($header->PE_IndicadorSolicitadoManual ?? 0) === 1;

        if ($kit !== '' && !$manualRequest) {
            $currentReference = trim((string) ($item->f120_referencia ?? ''));

            if ($currentReference !== '' && stripos($currentReference, $kit) === false) {
                throw new WorkerTaskProcessingException(
                    sprintf('El item %s del pedido no corresponde con el kit %s.', $itemId !== '' ? $itemId : $itemReference, $kit),
                    ['item_reference' => $currentReference, 'kit' => $kit]
                );
            }

            $referenceItem = $this->findItem($connection, '', trim((string) ($detail->PD_Referencia ?? '')));

            if (!$referenceItem instanceof stdClass) {
                throw new WorkerTaskProcessingException(
                    sprintf('No se encontro el item por referencia %s para el pedido.', trim((string) ($detail->PD_Referencia ?? ''))),
                    ['item_reference' => trim((string) ($detail->PD_Referencia ?? ''))]
                );
            }

            if ((string) ($referenceItem->f120_id ?? '') !== (string) ($item->f120_id ?? '')) {
                throw new WorkerTaskProcessingException(
                    sprintf(
                        'El pedido tiene diferencia entre el codigo item %s y la referencia %s.',
                        $itemId !== '' ? $itemId : $itemReference,
                        trim((string) ($detail->PD_Referencia ?? ''))
                    ),
                    [
                        'item_id' => $itemId,
                        'item_reference' => trim((string) ($detail->PD_Referencia ?? '')),
                        'resolved_item_id' => (string) ($item->f120_id ?? ''),
                        'resolved_reference_item_id' => (string) ($referenceItem->f120_id ?? ''),
                    ]
                );
            }
        }

        $movementOperationalCenter = trim((string) ($detail->f431_id_co_movto ?? ''));
        $documentType = trim((string) ($detail->f431_id_tipo_docto ?? ''));

        if ($movementOperationalCenter !== '' && $this->activationPeriodFor($connection, $movementOperationalCenter) >= 202011 && !in_array($documentType, ['PBM', 'PVM', 'PGM', 'PJM', 'PMM', 'PSM', 'PYM'], true)) {
            $detail->f431_id_co = $movementOperationalCenter;
        }

        $unitOfMeasure = $this->findInventoryUnit($connection, $itemId);

        if ($unitOfMeasure !== null) {
            $detail->f431_id_unidad_medida = $unitOfMeasure;
        }

        $sala = trim((string) ($header->PE_CodigoSalaDeVentas ?? ''));
        if ($sala === '0203') {
            $detail->f431_id_motivo = trim((string) ($header->PE_CodigoMotivo ?? '')) === 'ME' ? 'ME' : 'VE';
        }

        $itemInventoryType = trim((string) ($item->f120_id_tipo_inv_serv ?? ''));

        if (in_array($itemInventoryType, ['IN0505', 'IN0505EXT'], true)) {
            $detail->f431_id_motivo = '02';
        }

        if ((string) $item->f120_id === '900001') {
            $configuredMotive = trim((string) $this->config->get('workerhub.orders.detail.special_motives.fletenal', ''));
            if ($configuredMotive !== '') {
                $detail->f431_id_motivo = $configuredMotive;
            }
        }

        if ((string) $item->f120_id === '800013') {
            $configuredMotive = trim((string) $this->config->get('workerhub.orders.detail.special_motives.serepcol', ''));
            if ($configuredMotive !== '') {
                $detail->f431_id_motivo = $configuredMotive;
            }
        }

        if (in_array(trim((string) ($detail->f431_id_motivo ?? '')), ['34', 'ME'], true)) {
            $detail->f431_ind_obsequio = 1;
            $detail->f431_id_lista_precio = 'C37';
            $detail->f431_ind_impto_asumido = 1;
            $detail->f431_id_un_movto = '02';
            $detail->PD_TasaDescuento2 = 0;
        }

        if ((string) $item->f120_id === '110084' && trim((string) ($header->f419_id_depto ?? '')) !== '05') {
            $detail->f431_id_bodega = (string) $this->config->get('workerhub.orders.detail.special_reference_warehouses.dispverde', '00346');
        }

        return $detail;
    }

    private function buildAdditionalDetail(stdClass $header): string
    {
        $documentType = trim((string) ($header->PE_TipoDocumento ?? ''));
        $baseDetail = trim((string) ($header->PE_Detalle ?? $header->f430_notas ?? ''));

        if (in_array($documentType, ['FR', 'MU'], true)) {
            return $baseDetail;
        }

        $parts = [];
        $sala = trim((string) ($header->PE_CodigoSalaDeVentas ?? ''));

        if ($sala !== '') {
            $parts[] = sprintf('SALA[%s]', $sala);
        }

        $storeAbbreviation = trim((string) ($header->SA_AbreviacionSala ?? ''));
        if ($storeAbbreviation !== '') {
            $parts[] = $storeAbbreviation . '-';
        }

        if ($baseDetail !== '') {
            $parts[] = $baseDetail;
        }

        $notes = trim(implode(' ', $parts));
        $referredCode = trim((string) ($header->PE_CodigoReferido ?? ''));

        if ($referredCode !== '' && !in_array($sala, ['0240', '0242', '0250'], true)) {
            $notes = trim($notes . ' VREF[' . $referredCode . ']');
        }

        if ($sala === '') {
            $promoterCode = trim((string) ($header->PE_CodigoPromotor ?? ''));

            if ($promoterCode !== '') {
                $notes = trim($notes . ' VEN[' . $promoterCode . ']');
            }
        }

        $flyerNumber = trim((string) ($header->PE_NumeroVolante ?? ''));

        if ($flyerNumber !== '') {
            $notes = trim($notes . ' Volante: ' . $flyerNumber);
        }

        return $notes;
    }

    private function applyChainOverrides(ConnectionInterface $connection, stdClass $header): void
    {
        $documentType = trim((string) ($header->PE_TipoDocumento ?? ''));

        if ($documentType === '') {
            return;
        }

        $chainThirdParty = $connection->table($this->chainThirdPartiesTable())
            ->where('tipo_pedido', $documentType)
            ->value('nit');

        if (!is_scalar($chainThirdParty) || trim((string) $chainThirdParty) === '') {
            return;
        }

        $chainBranch = $connection->table($this->chainOrdersTable())
            ->where('rowid_pedido_sgc', $header->PE_RowId ?? null)
            ->value('suc');

        $header->f430_id_tercero_fact = trim((string) $chainThirdParty);
        $header->f430_id_tercero_rem = trim((string) $chainThirdParty);
        $header->f430_id_sucursal_fact = '001';
        $header->f430_id_sucursal_rem = trim((string) ($chainBranch ?? '001'));
        $header->f430_id_tipo_cli_fact = '0002';
        $header->f430_ind_estado = 1;
    }

    private function applyBackorderOverride(ConnectionInterface $connection, stdClass $header): void
    {
        $storeCode = trim((string) ($header->PE_CodigoSalaDeVentas ?? ''));

        if ($storeCode === '') {
            return;
        }

        $row = $connection->selectOne(
            sprintf(
                "SELECT TOP 1 ISNULL(t1.ind_backorder, t2.ind_backorder) AS ind_backorder
                FROM %s AS t1
                INNER JOIN %s AS t2 ON t1.company_id = t2.id
                WHERE t1.code_store = ?",
                $this->storesTable(),
                $this->companiesTable()
            ),
            [$storeCode]
        );

        if ($row instanceof stdClass && isset($row->ind_backorder)) {
            $header->f430_ind_backorder = $row->ind_backorder;
        }
    }

    private function findItem(ConnectionInterface $connection, string $itemId, string $itemReference): ?stdClass
    {
        if ($itemId !== '') {
            $row = $connection->selectOne(
                sprintf(
                    'SELECT TOP 1 f120_id, f120_referencia, f120_id_tipo_inv_serv FROM %s WHERE RTRIM(CONVERT(varchar(50), f120_id)) = ?',
                    $this->enterpriseItemsTable()
                ),
                [$itemId]
            );

            if ($row instanceof stdClass) {
                return $row;
            }
        }

        if ($itemReference === '') {
            return null;
        }

        return $connection->selectOne(
            sprintf(
                'SELECT TOP 1 f120_id, f120_referencia, f120_id_tipo_inv_serv FROM %s WHERE RTRIM(f120_referencia) = ?',
                $this->enterpriseItemsTable()
            ),
            [$itemReference]
        );
    }

    private function findInventoryUnit(ConnectionInterface $connection, string $itemId): ?string
    {
        if ($itemId === '') {
            return null;
        }

        $unit = $connection->table($this->itemUnitsTable())
            ->where('Cmitems_Codigo_Item_K1', $itemId)
            ->value('Cmitems_Und_medida_Inventar1');

        return is_scalar($unit) ? trim((string) $unit) : null;
    }

    private function activationPeriodFor(ConnectionInterface $connection, string $operationalCenter): int
    {
        $value = $connection->table($this->activationPeriodsTable())
            ->where('FC_co', $operationalCenter)
            ->value('FC_lapso_apertura');

        return is_numeric($value) ? (int) $value : 0;
    }

    private function connectionFor(string $sourceConnection): ConnectionInterface
    {
        $configuredConnections = (array) $this->config->get('workerhub.orders.source_connections', []);
        $connection = (string) ($configuredConnections[$sourceConnection] ?? $sourceConnection);

        if (!$this->database->connection($connection)->getPdo()) {
            throw new WorkerTaskProcessingException(
                sprintf('No se pudo abrir la conexion de origen para construir lineas del pedido: %s.', $connection),
                ['source_connection' => $sourceConnection, 'resolved_connection' => $connection]
            );
        }

        return $this->database->connection($connection);
    }

    /**
     * @param array<string, mixed>|stdClass $attributes
     */
    private function hydrate(object $connector, array|stdClass $attributes): object
    {
        foreach ((array) $attributes as $field => $value) {
            if (is_string($field) && property_exists($connector, $field)) {
                $connector->{$field} = $value;
            }
        }

        return $connector;
    }

    private function enterpriseItemsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.enterprise_state.tables.items', 'SiesaEnterprise.dbo.t120_mc_items');
    }

    private function itemUnitsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.item_units', 'maestros_uno.cmitems_catalogo_de_items');
    }

    private function activationPeriodsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.activation_periods', 'contabilidad.er_salas_fechas_activacion');
    }

    private function chainThirdPartiesTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.chain_third_parties', 'ventas.cadenas_tercero');
    }

    private function chainOrdersTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.chain_orders', 'ventas.pedidos_cadenas');
    }

    private function storesTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.stores', 'laravel_comodisimos.dbo.stores');
    }

    private function companiesTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.companies', 'laravel_comodisimos.dbo.companies');
    }

    private function ordersTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.orders', 'pos.pedidos_encabezado');
    }
}
