<?php

namespace App\Services\Workers\Orders;

use App\Data\Orders\OrderSiesaStateSnapshot;
use App\Exceptions\WorkerTaskProcessingException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use stdClass;

class OrderDeliveryGenerationRepository
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config
    ) {
    }

    public function shouldGenerateDomicile(stdClass $orderRecord): bool
    {
        return (int) ($orderRecord->PE_IndicadorAnulado ?? 0) === 0
            && (int) ($orderRecord->PE_EstadoVerificadoExportacion ?? 0) === 2
            && in_array((int) ($orderRecord->PE_Perfil ?? 0), [1, 5], true)
            && (int) ($orderRecord->PE_FechaDocumento ?? 0) >= 20210101;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function findActiveDomicileForEnterpriseOrder(array $payload, OrderSiesaStateSnapshot $snapshot): ?stdClass
    {
        if ($snapshot->rowId === null) {
            return null;
        }

        return $this->connectionFor($payload)
            ->table($this->domicileOrdersTable() . ' as domicile_order')
            ->join($this->domicilesTable() . ' as domicile', function ($join): void {
                $join->on('domicile.DE_TipoId', '=', 'domicile_order.DP_TipoId')
                    ->on('domicile.DE_Id', '=', 'domicile_order.DP_Id');
            })
            ->where('domicile_order.DP_rowid_pedido_enterprise', $snapshot->rowId)
            ->where('domicile.DE_Estado', '<', 3)
            ->select([
                'domicile_order.DP_TipoId',
                'domicile_order.DP_Id',
                'domicile.DE_rowid as DE_rowid',
            ])
            ->first();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function findReusableGiftParentDomicile(array $payload, stdClass $orderRecord): ?stdClass
    {
        return $this->connectionFor($payload)
            ->table($this->giftOrdersTable() . ' as gift_link')
            ->join($this->ordersTable() . ' as parent_order', function ($join): void {
                $join->on('parent_order.PE_CentroOperativo', '=', 'gift_link.co_pedido_origen')
                    ->on('parent_order.PE_TipoDocumento', '=', 'gift_link.tipo_pedido_origen')
                    ->on('parent_order.PE_NumeroDocumento', '=', 'gift_link.numero_pedido_origen');
            })
            ->where('gift_link.co_pedido_obsequio', trim((string) ($orderRecord->PE_CentroOperativo ?? '')))
            ->where('gift_link.tipo_pedido_obsequio', trim((string) ($orderRecord->PE_TipoDocumento ?? '')))
            ->where('gift_link.numero_pedido_obsequio', (int) ($orderRecord->PE_NumeroDocumento ?? 0))
            ->where('parent_order.PE_CodigoBodega', trim((string) ($orderRecord->PE_CodigoBodega ?? '')))
            ->whereNotNull('parent_order.PE_DomicilioTipo')
            ->whereNotNull('parent_order.PE_DomicilioNumero')
            ->select([
                'parent_order.PE_DomicilioTipo as PE_DomicilioTipo_Padre',
                'parent_order.PE_DomicilioNumero as PE_DomicilioNumero_Padre',
            ])
            ->first();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function findEnterpriseOrderContext(array $payload, OrderSiesaStateSnapshot $snapshot): stdClass
    {
        if ($snapshot->rowId === null) {
            throw new WorkerTaskProcessingException(
                'El pedido no tiene rowid enterprise para generar el domicilio.',
                ['payload' => $payload, 'siesa_state' => $snapshot->toArray()]
            );
        }

        $row = $this->connectionFor($payload)->selectOne(
            sprintf(
                "SELECT TOP 1
                    order_doc.f430_rowid,
                    RTRIM(order_doc.f430_id_co) AS f430_id_co,
                    RTRIM(order_doc.f430_id_tipo_docto) AS f430_id_tipo_docto,
                    RTRIM(CONVERT(varchar(50), order_doc.f430_consec_docto)) AS f430_consec_docto,
                    order_doc.f430_fecha_entrega,
                    order_doc.f430_ind_backorder,
                    order_doc.f430_id_clase_docto,
                    RTRIM(ISNULL(order_doc.f430_notas, '')) AS f430_notas,
                    RTRIM(third_party.f200_id) AS f200_id,
                    RTRIM(third_party.f200_razon_social) AS f200_razon_social,
                    RTRIM(ISNULL(contact.f419_direccion1, '')) AS f419_direccion1,
                    RTRIM(ISNULL(contact.f419_direccion2, '')) AS f419_direccion2,
                    RTRIM(ISNULL(contact.f419_direccion3, '')) AS f419_direccion3,
                    RTRIM(ISNULL(contact.f419_id_barrio, '')) AS f419_id_barrio,
                    RTRIM(ISNULL(contact.f419_telefono, '')) AS f419_telefono,
                    RTRIM(ISNULL(contact.f419_fax, '')) AS f419_fax,
                    RTRIM(ISNULL(contact.f419_celular, '')) AS f419_celular,
                    RTRIM(country.f011_id) AS f011_id,
                    RTRIM(department.f012_id) AS f012_id,
                    RTRIM(city.f013_id) AS f013_id,
                    RTRIM(ISNULL(city.f013_descripcion, '')) AS f013_descripcion,
                    RTRIM(ISNULL(department.f012_descripcion, '')) AS f012_descripcion
                FROM %s AS order_doc
                INNER JOIN %s AS client
                    ON client.f201_rowid_tercero = order_doc.f430_rowid_tercero_fact
                   AND client.f201_id_sucursal = order_doc.f430_id_sucursal_fact
                INNER JOIN %s AS third_party
                    ON third_party.f200_rowid = client.f201_rowid_tercero
                INNER JOIN %s AS contact
                    ON contact.f419_rowid = order_doc.f430_rowid_contacto_docto_rem
                INNER JOIN %s AS city
                    ON city.f013_id_pais = contact.f419_id_pais
                   AND city.f013_id_depto = contact.f419_id_depto
                   AND city.f013_id = contact.f419_id_ciudad
                INNER JOIN %s AS department
                    ON department.f012_id_pais = city.f013_id_pais
                   AND department.f012_id = city.f013_id_depto
                INNER JOIN %s AS country
                    ON country.f011_id = department.f012_id_pais
                WHERE order_doc.f430_rowid = ?",
                $this->enterpriseOrdersTable(),
                $this->enterpriseClientsTable(),
                $this->enterpriseThirdPartiesTable(),
                $this->enterpriseContactsTable(),
                $this->enterpriseCitiesTable(),
                $this->enterpriseDepartmentsTable(),
                $this->enterpriseCountriesTable()
            ),
            [$snapshot->rowId]
        );

        if (!$row instanceof stdClass) {
            throw new WorkerTaskProcessingException(
                'No se encontro el pedido enterprise para generar el domicilio.',
                ['payload' => $payload, 'siesa_state' => $snapshot->toArray()]
            );
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<stdClass>
     */
    public function findEnterpriseOrderDetailComponents(array $payload, OrderSiesaStateSnapshot $snapshot): array
    {
        if ($snapshot->rowId === null) {
            return [];
        }

        $rows = $this->connectionFor($payload)->select(
            sprintf(
                "SELECT
                    detail_view.v431_rowid AS f431_rowid,
                    CONVERT(char(8), order_doc.f430_id_fecha, 112) AS f431_fecha,
                    CASE WHEN child_item.f120_id IS NULL THEN parent_item.f120_id ELSE child_item.f120_id END AS f120_id,
                    CASE WHEN child_item.f120_id IS NULL THEN ISNULL(parent_extension.f121_id_ext1_detalle, '') ELSE ISNULL(child_extension.f121_id_ext1_detalle, '') END AS f121_id_ext1_detalle,
                    CASE WHEN child_item.f120_id IS NULL THEN parent_item.f120_id_unidad_inventario ELSE child_item.f120_id_unidad_inventario END AS f120_id_unidad_inventario,
                    ISNULL(parent_item.f120_id, '') AS f120_id_padre,
                    ISNULL(parent_extension.f121_id_ext1_detalle, '') AS f121_id_ext1_detalle_padre,
                    SUM(detail_view.v431_cant_pedida_base * (CASE WHEN child_item.f120_referencia IS NULL THEN 1 ELSE kit_component.f134_cant_requerida END)) AS f431_cant1_pedida
                FROM %s AS detail_view
                INNER JOIN %s AS order_doc
                    ON order_doc.f430_rowid = detail_view.v431_rowid_pv_docto
                INNER JOIN %s AS parent_extension
                    ON parent_extension.f121_rowid = detail_view.v431_rowid_item_ext
                INNER JOIN %s AS parent_item
                    ON parent_item.f120_rowid = parent_extension.f121_rowid_item
                LEFT JOIN %s AS kit_component
                    ON parent_extension.f121_rowid = kit_component.f134_rowid_item_ext_kit
                LEFT JOIN %s AS child_extension
                    ON child_extension.f121_rowid = kit_component.f134_rowid_item_ext_hijo
                LEFT JOIN %s AS child_item
                    ON child_item.f120_rowid = child_extension.f121_rowid_item
                WHERE order_doc.f430_rowid = ?
                GROUP BY
                    detail_view.v431_rowid,
                    CONVERT(char(8), order_doc.f430_id_fecha, 112),
                    CASE WHEN child_item.f120_id IS NULL THEN parent_item.f120_id ELSE child_item.f120_id END,
                    CASE WHEN child_item.f120_id IS NULL THEN ISNULL(parent_extension.f121_id_ext1_detalle, '') ELSE ISNULL(child_extension.f121_id_ext1_detalle, '') END,
                    CASE WHEN child_item.f120_id IS NULL THEN parent_item.f120_id_unidad_inventario ELSE child_item.f120_id_unidad_inventario END,
                    ISNULL(parent_item.f120_id, ''),
                    ISNULL(parent_extension.f121_id_ext1_detalle, '')",
                $this->enterpriseOrderDetailView(),
                $this->enterpriseOrdersTable(),
                $this->enterpriseItemExtensionsTable(),
                $this->enterpriseItemsTable(),
                $this->enterpriseKitComponentsTable(),
                $this->enterpriseItemExtensionsTable(),
                $this->enterpriseItemsTable()
            ),
            [$snapshot->rowId]
        );

        return array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof stdClass));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function resolveRequestedDomicileType(array $payload, stdClass $orderRecord, OrderSiesaStateSnapshot $snapshot): string
    {
        $documentType = trim((string) ($orderRecord->PE_TipoDocumento ?? ''));
        $specialDocumentTypes = (array) $this->config->get('workerhub.orders.delivery_generation.special_document_types', []);

        if (in_array($documentType, $specialDocumentTypes, true)) {
            return 'DDES';
        }

        $unitMovement = $this->findFirstUnitMovementForEnterpriseOrder($payload, $snapshot);

        if ($unitMovement === '01') {
            return 'DDES';
        }

        return (string) $this->config->get('workerhub.orders.delivery_generation.default_type', 'DMED');
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<stdClass> $detailLines
     * @return array{type:string,number:int,row_id:int|null,mode:string}
     */
    public function createDomicileForEnterpriseOrder(
        array $payload,
        stdClass $orderRecord,
        stdClass $enterpriseOrder,
        array $detailLines,
        string $requestedDomicileType
    ): array {
        $connection = $this->connectionFor($payload);

        return $connection->transaction(function () use ($connection, $payload, $orderRecord, $enterpriseOrder, $detailLines, $requestedDomicileType): array {
            $dispatchWarehouse = $this->resolveDispatchWarehouseEquivalent(
                $this->findDispatchWarehouseForEnterpriseOrder($connection, (int) $enterpriseOrder->f430_rowid)
            );
            $warehouseProperties = $this->findDispatchWarehouseProperties($connection, $dispatchWarehouse);
            $domicileType = $this->resolveFinalDomicileType($warehouseProperties, $requestedDomicileType);
            $classificationType = $this->resolveClassificationType($enterpriseOrder);
            $cityId = trim((string) ($enterpriseOrder->f011_id . $enterpriseOrder->f012_id . $enterpriseOrder->f013_id));
            $departmentId = trim((string) ($enterpriseOrder->f011_id . $enterpriseOrder->f012_id . '000'));
            $customerDeliveryDate = Carbon::parse((string) $enterpriseOrder->f430_fecha_entrega)->startOfDay();
            $logisticDeliveryDate = $this->resolveLogisticDeliveryDate($connection, $customerDeliveryDate, $dispatchWarehouse, $cityId);

            if ($logisticDeliveryDate === null || $logisticDeliveryDate->equalTo($customerDeliveryDate)) {
                throw new WorkerTaskProcessingException(
                    sprintf(
                        'Al pedido EP%s%s%s no se le puede crear domicilio porque no tiene bodega origen valida.',
                        trim((string) $enterpriseOrder->f430_id_co),
                        trim((string) $enterpriseOrder->f430_id_tipo_docto),
                        trim((string) $enterpriseOrder->f430_consec_docto)
                    ),
                    ['payload' => $payload]
                );
            }

            $dispatchDate = $this->resolveDispatchDate($connection, $logisticDeliveryDate);
            $realDeliveryDate = null;
            $pickupDate = null;

            if (in_array($classificationType, [1, 4], true)) {
                $realDeliveryDate = $customerDeliveryDate->copy();
            } else {
                if ($dispatchDate->lessThan(Carbon::today())) {
                    $dispatchDate = Carbon::today();
                }

                $pickupDate = $customerDeliveryDate->copy();
                $realDeliveryDate = $this->resolveRealDeliveryDateFromPickup($connection, $pickupDate, $dispatchWarehouse, $cityId);
            }

            $domicileNumber = $this->reserveNextDomicileNumber($connection, $domicileType);
            $enterpriseOrderKey = $this->buildEnterpriseOrderKey(
                trim((string) $enterpriseOrder->f430_id_co),
                trim((string) $enterpriseOrder->f430_id_tipo_docto),
                trim((string) $enterpriseOrder->f430_consec_docto)
            );
            $addressComplementId = isset($orderRecord->PE_address_address_complement_id) && is_numeric($orderRecord->PE_address_address_complement_id)
                ? (int) $orderRecord->PE_address_address_complement_id
                : null;
            $sectorId = $this->resolveSectorId($connection, $domicileType, $departmentId, $addressComplementId, $cityId);

            $connection->table($this->domicilesTable())->insert([
                'DE_TipoId' => $domicileType,
                'DE_Id' => $domicileNumber,
                'DE_Fecha' => $logisticDeliveryDate->toDateString(),
                'DE_IdCliente' => trim((string) $enterpriseOrder->f200_id),
                'DE_Nombre' => mb_substr(trim((string) $enterpriseOrder->f200_razon_social), 0, 60),
                'DE_Direccion' => $this->buildEnterpriseAddress($enterpriseOrder),
                'DE_Barrio' => trim((string) ($enterpriseOrder->f419_id_barrio ?? '')),
                'DE_Ciudad' => trim((string) ($enterpriseOrder->f013_descripcion ?? '')),
                'DE_Telefono' => trim((string) ($enterpriseOrder->f419_telefono ?? '')),
                'DE_Telefono1' => trim((string) ($enterpriseOrder->f419_fax ?? '')),
                'DE_Celular' => trim((string) ($enterpriseOrder->f419_celular ?? '')),
                'DE_IndicadorContraEntrega' => 0,
                'DE_ValorContraEntrega' => 0,
                'DE_NombreRecibe' => '',
                'DE_IndicadorImpreso' => 0,
                'DE_FechaRegistro' => now(),
                'DE_IdUsuario' => $this->serviceUserId(),
                'DE_Observaciones' => trim((string) ($enterpriseOrder->f430_notas ?? '')),
                'DE_Sector' => $sectorId,
                'DE_Sala' => '',
                'DE_Pedido' => $enterpriseOrderKey,
                'DE_Estado' => -4,
                'DE_Horario' => 'AM',
                'DE_IdCiudad' => $cityId,
                'DE_IdDepartamento' => $departmentId,
                'DE_Departamento' => trim((string) ($enterpriseOrder->f012_descripcion ?? '')),
                'DE_IndicadorOtraCiudad' => 0,
                'DE_Factura' => '',
                'DE_FechaRealEntrega' => $realDeliveryDate?->toDateString(),
                'DE_IndicadorHabilitadoCartera' => 1,
                'DE_CantidadDevolucionesAsesor' => 0,
                'DE_FechaDespacho' => $dispatchDate->toDateString(),
                'DE_FechaCreacion' => now(),
                'DE_TipoClasificacion' => $classificationType,
                'DE_FechaRecogida' => $pickupDate?->toDateString(),
                'DE_PE_AddressAddressComplementId' => $addressComplementId,
                'DE_BackOrder' => (int) ($enterpriseOrder->f430_ind_backorder ?? 0),
            ]);

            $connection->table($this->domicileOrdersTable())->insert([
                'DP_TipoId' => $domicileType,
                'DP_Id' => $domicileNumber,
                'DP_NumeroPedido' => $enterpriseOrderKey,
                'DP_FechaRegistro' => now(),
                'DP_IdUsuario' => $this->serviceUserId(),
                'DP_Factura' => '',
                'DP_NumeroPedidoEnterprise' => $enterpriseOrderKey,
                'DP_Estado' => 0,
                'DP_rowid_pedido_enterprise' => (int) $enterpriseOrder->f430_rowid,
            ]);

            $this->insertDomicileOrderDetailRows($connection, $domicileType, $domicileNumber, $enterpriseOrderKey, $detailLines);
            $this->populateEnterpriseOrderRowIds($connection);
            $this->insertDomicileMovementLog(
                $connection,
                $domicileType,
                $domicileNumber,
                trim((string) ($enterpriseOrder->f430_notas ?? ''))
            );
            $this->executePickingMatchProcedure($connection);

            $domicileRowId = $this->findDomicileRowId($connection, $domicileType, $domicileNumber);

            return [
                'type' => $domicileType,
                'number' => $domicileNumber,
                'row_id' => $domicileRowId,
                'mode' => 'created',
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<stdClass> $detailLines
     * @return array{type:string,number:int,row_id:int|null,mode:string}
     */
    public function attachEnterpriseOrderToExistingDomicile(
        array $payload,
        OrderSiesaStateSnapshot $snapshot,
        array $detailLines,
        string $domicileType,
        int $domicileNumber
    ): array {
        if ($snapshot->rowId === null || $snapshot->enterpriseOperationalCenter === null || $snapshot->enterpriseDocumentType === null || $snapshot->enterpriseDocumentNumber === null) {
            throw new WorkerTaskProcessingException(
                'El pedido no tiene referencia enterprise valida para adicionarlo a un domicilio existente.',
                ['payload' => $payload, 'siesa_state' => $snapshot->toArray()]
            );
        }

        $connection = $this->connectionFor($payload);
        $enterpriseOrderKey = $this->buildEnterpriseOrderKey(
            $snapshot->enterpriseOperationalCenter,
            $snapshot->enterpriseDocumentType,
            $snapshot->enterpriseDocumentNumber
        );

        return $connection->transaction(function () use ($connection, $snapshot, $detailLines, $domicileType, $domicileNumber, $enterpriseOrderKey): array {
            $connection->table($this->domicileOrdersTable())->insert([
                'DP_TipoId' => $domicileType,
                'DP_Id' => $domicileNumber,
                'DP_NumeroPedido' => $enterpriseOrderKey,
                'DP_FechaRegistro' => now(),
                'DP_IdUsuario' => $this->serviceUserId(),
                'DP_Factura' => '',
                'DP_NumeroPedidoEnterprise' => $enterpriseOrderKey,
                'DP_Estado' => 0,
                'DP_rowid_pedido_enterprise' => $snapshot->rowId,
            ]);

            $this->insertDomicileOrderDetailRows($connection, $domicileType, $domicileNumber, $enterpriseOrderKey, $detailLines);
            $this->populateEnterpriseOrderRowIds($connection);
            $this->insertDomicileMovementLog($connection, $domicileType, $domicileNumber, '');

            return [
                'type' => $domicileType,
                'number' => $domicileNumber,
                'row_id' => $this->findDomicileRowId($connection, $domicileType, $domicileNumber),
                'mode' => 'attached_to_parent',
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function connectionFor(array $payload): ConnectionInterface
    {
        $sourceConnection = trim((string) ($payload['db_connection'] ?? ''));
        $configuredConnections = (array) $this->config->get('workerhub.orders.source_connections', []);
        $connection = (string) ($configuredConnections[$sourceConnection] ?? $sourceConnection);

        if ($connection === '') {
            throw new WorkerTaskProcessingException(
                'El payload del domicilio no tiene db_connection valida.',
                ['payload' => $payload]
            );
        }

        return $this->database->connection($connection);
    }

    private function findFirstUnitMovementForEnterpriseOrder(array $payload, OrderSiesaStateSnapshot $snapshot): string
    {
        if ($snapshot->rowId === null) {
            return '';
        }

        $row = $this->connectionFor($payload)->selectOne(
            sprintf(
                "SELECT TOP 1 RTRIM(ISNULL(f431_id_un_movto, '')) AS f431_id_un_movto
                FROM %s
                WHERE f431_rowid_pv_docto = ?",
                $this->enterpriseOrderLinesTable()
            ),
            [$snapshot->rowId]
        );

        return $row instanceof stdClass ? trim((string) ($row->f431_id_un_movto ?? '')) : '';
    }

    private function findDispatchWarehouseForEnterpriseOrder(ConnectionInterface $connection, int $enterpriseRowId): string
    {
        $row = $connection->selectOne(
            sprintf(
                "SELECT TOP 1 RTRIM(warehouse.f150_id) AS f150_id
                FROM %s AS order_line
                INNER JOIN %s AS warehouse
                    ON warehouse.f150_rowid = order_line.f431_rowid_bodega
                WHERE order_line.f431_rowid_pv_docto = ?",
                $this->enterpriseOrderLinesTable(),
                $this->enterpriseWarehousesTable()
            ),
            [$enterpriseRowId]
        );

        return $row instanceof stdClass ? trim((string) ($row->f150_id ?? '')) : '';
    }

    private function resolveDispatchWarehouseEquivalent(string $warehouseId): string
    {
        $warehouseId = trim($warehouseId);
        $equivalents = (array) $this->config->get('workerhub.orders.delivery_generation.dispatch_warehouse_equivalents', []);

        return (string) ($equivalents[$warehouseId] ?? $warehouseId);
    }

    private function findDispatchWarehouseProperties(ConnectionInterface $connection, string $dispatchWarehouseId): ?stdClass
    {
        if ($dispatchWarehouseId === '') {
            return null;
        }

        return $connection->selectOne(
            sprintf(
                "SELECT TOP 1 request_box.*, sale_info.SA_Bodega
                FROM %s AS request_box
                INNER JOIN %s AS sale_info
                    ON sale_info.SA_Id = request_box.DC_IdSala
                WHERE sale_info.SA_Bodega = ?
                  AND request_box.DC_IndicadorActivo = 1",
                $this->dispatchRequestsTable(),
                $this->salesInformationTable()
            ),
            [$dispatchWarehouseId]
        );
    }

    private function resolveFinalDomicileType(?stdClass $warehouseProperties, string $requestedDomicileType): string
    {
        if (
            $warehouseProperties instanceof stdClass
            && trim((string) ($warehouseProperties->DC_TipoDomicilio ?? '')) !== ''
            && trim((string) ($warehouseProperties->DC_Bodega ?? '')) !== '00141'
        ) {
            return trim((string) $warehouseProperties->DC_TipoDomicilio);
        }

        return trim($requestedDomicileType);
    }

    private function resolveClassificationType(stdClass $enterpriseOrder): int
    {
        $documentClassId = (int) ($enterpriseOrder->f430_id_clase_docto ?? 0);
        $documentType = trim((string) ($enterpriseOrder->f430_id_tipo_docto ?? ''));

        if ($documentClassId === 502) {
            return 1;
        }

        if ($documentClassId === 508 && $documentType === 'PRR') {
            return 2;
        }

        if ($documentClassId === 508 && $documentType === 'PDV') {
            return 3;
        }

        return 1;
    }

    private function resolveLogisticDeliveryDate(
        ConnectionInterface $connection,
        Carbon $customerDeliveryDate,
        string $dispatchWarehouseId,
        string $cityId
    ): ?Carbon {
        if ($dispatchWarehouseId === '' || $cityId === '') {
            return null;
        }

        $dispatchOrigin = $connection->selectOne(
            sprintf(
                "SELECT TOP 1 request_box.DC_Id
                FROM %s AS request_box
                INNER JOIN %s AS sale_info
                    ON sale_info.SA_Id = request_box.DC_IdSala
                WHERE sale_info.SA_Bodega = ?
                  AND request_box.DC_IndicadorActivo = 1",
                $this->dispatchRequestsTable(),
                $this->salesInformationTable()
            ),
            [$dispatchWarehouseId]
        );

        if (!$dispatchOrigin instanceof stdClass || !is_numeric($dispatchOrigin->DC_Id ?? null)) {
            return null;
        }

        $row = $connection->selectOne(
            "SELECT maestros_uno.fun_obtener_fecha_habil(?, pos.fun_usp_obtener_fecha_minima_permitidaV2_dias_logistica(?, ?) * -1) AS delivery_date",
            [$customerDeliveryDate->toDateString(), (int) $dispatchOrigin->DC_Id, $cityId]
        );

        if (!$row instanceof stdClass || empty($row->delivery_date)) {
            return null;
        }

        return Carbon::parse((string) $row->delivery_date)->startOfDay();
    }

    private function resolveDispatchDate(ConnectionInterface $connection, Carbon $logisticDeliveryDate): Carbon
    {
        $row = $connection->selectOne(
            "SELECT maestros_uno.fun_obtener_fecha_habil(?, 1) AS dispatch_date",
            [$logisticDeliveryDate->toDateString()]
        );

        return $row instanceof stdClass && !empty($row->dispatch_date)
            ? Carbon::parse((string) $row->dispatch_date)->startOfDay()
            : $logisticDeliveryDate->copy();
    }

    private function resolveRealDeliveryDateFromPickup(
        ConnectionInterface $connection,
        Carbon $pickupDate,
        string $dispatchWarehouseId,
        string $cityId
    ): Carbon {
        $dispatchOrigin = $connection->selectOne(
            sprintf(
                "SELECT TOP 1 request_box.DC_Id
                FROM %s AS request_box
                INNER JOIN %s AS sale_info
                    ON sale_info.SA_Id = request_box.DC_IdSala
                WHERE sale_info.SA_Bodega = ?
                  AND request_box.DC_IndicadorActivo = 1",
                $this->dispatchRequestsTable(),
                $this->salesInformationTable()
            ),
            [$dispatchWarehouseId]
        );

        if (!$dispatchOrigin instanceof stdClass || !is_numeric($dispatchOrigin->DC_Id ?? null)) {
            return $pickupDate->copy();
        }

        $row = $connection->selectOne(
            "SELECT maestros_uno.fun_obtener_fecha_habil(?, pos.fun_usp_obtener_fecha_minima_permitidaV2_dias_logistica(?, ?)) AS delivery_date",
            [$pickupDate->toDateString(), (int) $dispatchOrigin->DC_Id, $cityId]
        );

        return $row instanceof stdClass && !empty($row->delivery_date)
            ? Carbon::parse((string) $row->delivery_date)->startOfDay()
            : $pickupDate->copy();
    }

    private function reserveNextDomicileNumber(ConnectionInterface $connection, string $domicileType): int
    {
        $connection->update(
            sprintf("UPDATE %s SET NSNUM = NSNUM + 1 WHERE NSID = ?", $this->nextNumbersTable()),
            [$domicileType]
        );

        $row = $connection->selectOne(
            sprintf("SELECT NSNUM FROM %s WHERE NSID = ?", $this->nextNumbersTable()),
            [$domicileType]
        );

        if (!$row instanceof stdClass || !is_numeric($row->NSNUM ?? null)) {
            throw new WorkerTaskProcessingException(
                sprintf('No se encontro consecutivo para el tipo de domicilio %s.', $domicileType),
                ['domicile_type' => $domicileType]
            );
        }

        return (int) $row->NSNUM;
    }

    /**
     * @param list<stdClass> $detailLines
     */
    private function insertDomicileOrderDetailRows(
        ConnectionInterface $connection,
        string $domicileType,
        int $domicileNumber,
        string $enterpriseOrderKey,
        array $detailLines
    ): void {
        $records = [];

        foreach (array_values($detailLines) as $index => $detailLine) {
            if (!$detailLine instanceof stdClass) {
                continue;
            }

            $records[] = [
                'DD_TipoId' => $domicileType,
                'DD_Id' => $domicileNumber,
                'DD_NumeroPedido' => $enterpriseOrderKey,
                'DD_NumeroRegistro' => $index + 1,
                'DD_CodigoItem' => trim((string) ($detailLine->f120_id ?? '')),
                'DD_Ext' => trim((string) ($detailLine->f121_id_ext1_detalle ?? '')),
                'DD_Cantidad' => (float) ($detailLine->f431_cant1_pedida ?? 0),
                'DD_UND' => trim((string) ($detailLine->f120_id_unidad_inventario ?? '')),
                'DD_CodigoItemPadre' => trim((string) ($detailLine->f120_id_padre ?? '')),
                'DD_ExtPadre' => trim((string) ($detailLine->f121_id_ext1_detalle_padre ?? '')),
            ];
        }

        if ($records !== []) {
            $connection->table($this->domicileOrderDetailsTable())->insert($records);
        }
    }

    private function populateEnterpriseOrderRowIds(ConnectionInterface $connection): void
    {
        $connection->statement(
            sprintf(
                "UPDATE domicile_order
                    SET domicile_order.DP_rowid_pedido_enterprise = enterprise_order.f430_rowid
                FROM %s AS domicile_order
                INNER JOIN %s AS enterprise_order
                    ON enterprise_order.f430_id_co = SUBSTRING(domicile_order.DP_NumeroPedidoEnterprise, 3, 3)
                   AND enterprise_order.f430_id_tipo_docto = SUBSTRING(domicile_order.DP_NumeroPedidoEnterprise, 6, 3)
                   AND enterprise_order.f430_consec_docto = CONVERT(int, SUBSTRING(domicile_order.DP_NumeroPedidoEnterprise, 9, 7))
                WHERE ISNULL(domicile_order.DP_rowid_pedido_enterprise, 0) <> enterprise_order.f430_rowid
                  AND enterprise_order.f430_ind_estado NOT IN (4, 9)
                  AND domicile_order.DP_rowid_pedido_enterprise IS NULL",
                $this->domicileOrdersTable(),
                $this->enterpriseOrdersTable()
            )
        );

        $connection->statement(
            sprintf(
                "UPDATE domicile_order
                    SET domicile_order.DP_rowid_encabezado_domicilio = domicile.DE_rowid
                FROM %s AS domicile_order
                INNER JOIN %s AS domicile
                    ON domicile.DE_TipoId = domicile_order.DP_TipoId
                   AND domicile.DE_Id = domicile_order.DP_Id
                WHERE domicile_order.DP_rowid_encabezado_domicilio IS NULL",
                $this->domicileOrdersTable(),
                $this->domicilesTable()
            )
        );

        $connection->statement(
            sprintf(
                "UPDATE domicile_detail
                    SET domicile_detail.DD_rowid_encabezado_pedido = domicile_order.DP_rowid
                FROM %s AS domicile_detail
                LEFT JOIN %s AS domicile_order
                    ON domicile_order.DP_TipoId = domicile_detail.DD_TipoId
                   AND domicile_order.DP_Id = domicile_detail.DD_Id
                WHERE domicile_detail.DD_rowid_encabezado_pedido IS NULL",
                $this->domicileOrderDetailsTable(),
                $this->domicileOrdersTable()
            )
        );
    }

    private function insertDomicileMovementLog(
        ConnectionInterface $connection,
        string $domicileType,
        int $domicileNumber,
        string $notes
    ): void {
        $connection->table($this->movementLogsTable())->insert([
            'BI_TipoDocumento' => $domicileType,
            'BI_NumeroDocumento' => $domicileNumber,
            'BI_IdProceso' => 1,
            'BI_Fecha' => now(),
            'BI_IdUsuario' => $this->serviceUserId(),
            'BI_Log' => $notes,
        ]);
    }

    private function executePickingMatchProcedure(ConnectionInterface $connection): void
    {
        $connection->statement(sprintf('EXEC %s', $this->pickMatchingProcedure()));
    }

    private function findDomicileRowId(ConnectionInterface $connection, string $domicileType, int $domicileNumber): ?int
    {
        $row = $connection->table($this->domicilesTable())
            ->select('DE_rowid')
            ->where('DE_TipoId', $domicileType)
            ->where('DE_Id', $domicileNumber)
            ->first();

        return $row instanceof stdClass && is_numeric($row->DE_rowid ?? null)
            ? (int) $row->DE_rowid
            : null;
    }

    private function resolveSectorId(
        ConnectionInterface $connection,
        string $domicileType,
        string $departmentId,
        ?int $addressComplementId,
        string $cityId
    ): int {
        if ($domicileType === 'DDES' && $departmentId === '16905000') {
            return 14;
        }

        if ($addressComplementId !== null && $addressComplementId > 0) {
            $postalSector = $connection->selectOne(
                sprintf(
                    "SELECT TOP 1 postal_sector.cps_id_sector
                    FROM laravel_comodisimos.dbo.address_address_complement AS address_link
                    INNER JOIN laravel_comodisimos.dbo.addresses AS address_book
                        ON address_book.id = address_link.address_id
                    INNER JOIN %s AS postal_sector
                        ON postal_sector.cps_codigo_postal = address_book.postal_code
                    WHERE address_link.id = ?",
                    $this->postalCodeSectorsTable()
                ),
                [$addressComplementId]
            );

            if ($postalSector instanceof stdClass && is_numeric($postalSector->cps_id_sector ?? null)) {
                return (int) $postalSector->cps_id_sector;
            }
        }

        $citySector = $connection->table($this->citySectorsTable())
            ->select('CS_id_sector')
            ->where('CS_id_ciudad', $cityId)
            ->first();

        return $citySector instanceof stdClass && is_numeric($citySector->CS_id_sector ?? null)
            ? (int) $citySector->CS_id_sector
            : 0;
    }

    private function buildEnterpriseAddress(stdClass $enterpriseOrder): string
    {
        return trim(sprintf(
            '%s%s%s',
            (string) ($enterpriseOrder->f419_direccion1 ?? ''),
            (string) ($enterpriseOrder->f419_direccion2 ?? ''),
            (string) ($enterpriseOrder->f419_direccion3 ?? '')
        ));
    }

    private function buildEnterpriseOrderKey(string $co, string $type, string $number): string
    {
        return strtoupper(sprintf(
            'EP%s%s%s',
            str_pad(trim($co), 3, '0', STR_PAD_LEFT),
            str_pad(trim($type), 3, ' ', STR_PAD_RIGHT),
            str_pad(trim($number), 7, '0', STR_PAD_LEFT)
        ));
    }

    private function ordersTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.orders', 'pos.pedidos_encabezado');
    }

    private function giftOrdersTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.gift_orders', 'pos.pedido_obsequios');
    }

    private function domicilesTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.domiciles', 'logistica.domicilios_encabezado');
    }

    private function domicileOrdersTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.domicile_orders', 'logistica.domicilios_pedido_encabezado');
    }

    private function domicileOrderDetailsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.domicile_order_details', 'logistica.domicilios_pedido_detalle');
    }

    private function movementLogsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.movement_logs', 'aplicacion.bitacora_movimientos');
    }

    private function nextNumbersTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.next_numbers', 'aplicacion.numerosiguiente');
    }

    private function dispatchRequestsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.dispatch_requests', 'pos.solicitud_despacho_correos');
    }

    private function salesInformationTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.sales_information', 'pos.salas_informacion_adicional');
    }

    private function citySectorsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.city_sectors', 'logistica.ciudad_sector');
    }

    private function postalCodeSectorsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.postal_code_sectors', 'logistica.codigo_postal_sector');
    }

    private function enterpriseOrdersTable(): string
    {
        return (string) $this->config->get('workerhub.orders.enterprise_state.tables.orders', 'SiesaEnterprise.dbo.t430_cm_pv_docto');
    }

    private function enterpriseOrderLinesTable(): string
    {
        return (string) $this->config->get('workerhub.orders.enterprise_state.tables.order_lines', 'SiesaEnterprise.dbo.t431_cm_pv_movto');
    }

    private function enterpriseOrderDetailView(): string
    {
        return (string) $this->config->get('workerhub.orders.enterprise_state.tables.order_detail_view', 'SiesaEnterprise.dbo.v431');
    }

    private function enterpriseItemsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.enterprise_state.tables.items', 'SiesaEnterprise.dbo.t120_mc_items');
    }

    private function enterpriseItemExtensionsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.enterprise_state.tables.item_extensions', 'SiesaEnterprise.dbo.t121_mc_items_extensiones');
    }

    private function enterpriseKitComponentsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.enterprise_state.tables.kit_components', 'SiesaEnterprise.dbo.t134_mc_items_kits');
    }

    private function enterpriseWarehousesTable(): string
    {
        return (string) $this->config->get('workerhub.orders.enterprise_state.tables.warehouses', 'SiesaEnterprise.dbo.t150_mc_bodegas');
    }

    private function enterpriseThirdPartiesTable(): string
    {
        return (string) $this->config->get('workerhub.orders.enterprise_state.tables.third_parties', 'SiesaEnterprise.dbo.t200_mm_terceros');
    }

    private function enterpriseClientsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.enterprise_state.tables.clients', 'SiesaEnterprise.dbo.t201_mm_clientes');
    }

    private function enterpriseContactsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.enterprise_state.tables.contacts', 'SiesaEnterprise.dbo.t419_mc_contactos_docto');
    }

    private function enterpriseCountriesTable(): string
    {
        return (string) $this->config->get('workerhub.orders.enterprise_state.tables.countries', 'SiesaEnterprise.dbo.t011_mm_paises');
    }

    private function enterpriseDepartmentsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.enterprise_state.tables.departments', 'SiesaEnterprise.dbo.t012_mm_deptos');
    }

    private function enterpriseCitiesTable(): string
    {
        return (string) $this->config->get('workerhub.orders.enterprise_state.tables.cities', 'SiesaEnterprise.dbo.t013_mm_ciudades');
    }

    private function pickMatchingProcedure(): string
    {
        return (string) $this->config->get('workerhub.orders.delivery_generation.pick_matching_procedure', 'logistica.usp_lista_picking_entregas_marcar_match');
    }

    private function serviceUserId(): int
    {
        return (int) $this->config->get('workerhub.orders.delivery_generation.service_user_id', 285);
    }
}
