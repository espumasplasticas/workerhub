<?php

namespace App\Services\Workers\Receipts;

use App\Contracts\ReceiptCustomerSyncDataSourceInterface;
use App\Data\Receipts\ReceiptCustomerSyncSnapshot;
use App\Exceptions\WorkerTaskProcessingException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use stdClass;

class SqlReceiptCustomerSyncDataSource implements ReceiptCustomerSyncDataSourceInterface
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config
    ) {
    }

    public function fetch(array $payload, string $enterpriseOperationalCenter): ReceiptCustomerSyncSnapshot
    {
        $reference = $this->resolveReference($payload);
        $connection = $this->connectionFor($reference['db_connection']);
        $receipt = $this->findReceipt($connection, $reference);
        $thirdPartyId = trim((string) ($receipt->RE_CodigoTercero ?? ''));
        $sourceBranch = trim((string) ($receipt->RE_CodigoSucursal ?? '00'));

        if ($thirdPartyId === '') {
            throw new WorkerTaskProcessingException(
                'El recibo no tiene tercero asociado para sincronizar antes de migrar.',
                ['reference' => $reference]
            );
        }

        $customer = $this->findCustomer($connection, $thirdPartyId, $sourceBranch);

        return $this->buildSnapshot($connection, $customer, $enterpriseOperationalCenter);
    }

    public function fetchThirdParty(
        array $payload,
        string $thirdPartyId,
        ?string $branchHint,
        string $enterpriseOperationalCenter
    ): ReceiptCustomerSyncSnapshot {
        $reference = $this->resolveReference($payload);
        $connection = $this->connectionFor($reference['db_connection']);
        $customer = $this->findCustomerByThirdParty($connection, trim($thirdPartyId), $branchHint);

        return $this->buildSnapshot($connection, $customer, $enterpriseOperationalCenter);
    }

    /**
     * @param array{operational_center: string, document_type: string, document_number: string} $reference
     */
    private function findReceipt(ConnectionInterface $connection, array $reference): stdClass
    {
        $receipt = $connection
            ->table($this->receiptTable())
            ->select([
                'RE_CodigoTercero',
                'RE_CodigoSucursal',
            ])
            ->where('RE_CentroOperativo', $reference['operational_center'])
            ->where('RE_TipoDocumento', $reference['document_type'])
            ->where('RE_NumeroDocumento', $reference['document_number'])
            ->first();

        if (!$receipt instanceof stdClass) {
            throw new WorkerTaskProcessingException(
                'No se encontro el recibo para sincronizar tercero antes de migrar.',
                ['reference' => $reference]
            );
        }

        return $receipt;
    }

    private function findCustomer(ConnectionInterface $connection, string $thirdPartyId, string $sourceBranch): stdClass
    {
        $customer = $connection
            ->table($this->customersTable())
            ->where('CL_CodigoTercero', $thirdPartyId)
            ->where('CL_Sucursal', $sourceBranch)
            ->first();

        if (!$customer instanceof stdClass) {
            throw new WorkerTaskProcessingException(
                sprintf(
                    'No se encontro el cliente %s sucursal %s para sincronizar antes del recibo.',
                    $thirdPartyId,
                    $sourceBranch
                ),
                ['third_party_id' => $thirdPartyId, 'source_branch' => $sourceBranch]
            );
        }

        return $customer;
    }

    private function findCustomerByThirdParty(ConnectionInterface $connection, string $thirdPartyId, ?string $branchHint): stdClass
    {
        if ($thirdPartyId === '') {
            throw new WorkerTaskProcessingException('El encabezado del recibo no informa el tercero dependiente a sincronizar.');
        }

        $customers = $connection
            ->table($this->customersTable())
            ->where('CL_CodigoTercero', $thirdPartyId)
            ->get();

        if ($customers->isEmpty()) {
            throw new WorkerTaskProcessingException(
                sprintf('No se encontro el tercero dependiente %s en la tabla de clientes POS.', $thirdPartyId),
                ['third_party_id' => $thirdPartyId, 'branch_hint' => $branchHint]
            );
        }

        $normalizedHint = $this->normalizeEnterpriseBranch($branchHint);
        $sourceHint = $this->normalizeSourceBranchHint($branchHint);

        foreach ($customers as $customer) {
            $sourceBranch = trim((string) ($customer->CL_Sucursal ?? ''));

            if ($sourceHint !== '' && $sourceBranch === $sourceHint) {
                return $customer;
            }
        }

        foreach ($customers as $customer) {
            $sourceBranch = trim((string) ($customer->CL_Sucursal ?? ''));

            if ($normalizedHint !== '' && $this->enterpriseBranchForSourceBranch($sourceBranch) === $normalizedHint) {
                return $customer;
            }
        }

        return $customers->first();
    }

    private function findCustomerClass(ConnectionInterface $connection, string $customerClassId): stdClass
    {
        if ($customerClassId === '') {
            throw new WorkerTaskProcessingException('El cliente del recibo no tiene clase de cliente configurada.');
        }

        $customerClass = $connection
            ->table($this->customerClassesTable())
            ->select(['CC_Id', 'CC_PermiteSeleccionar'])
            ->where('CC_Id', $customerClassId)
            ->first();

        if (!$customerClass instanceof stdClass) {
            throw new WorkerTaskProcessingException(
                sprintf('La clase de cliente %s no existe para sincronizar el tercero.', $customerClassId),
                ['customer_class_id' => $customerClassId]
            );
        }

        return $customerClass;
    }

    private function buildSnapshot(
        ConnectionInterface $connection,
        stdClass $customer,
        string $enterpriseOperationalCenter
    ): ReceiptCustomerSyncSnapshot {
        $thirdPartyId = trim((string) ($customer->CL_CodigoTercero ?? ''));
        $sourceBranch = trim((string) ($customer->CL_Sucursal ?? '00'));
        $customerClassId = trim((string) ($customer->CL_ClaseDeCliente ?? ''));
        $customerClass = $this->findCustomerClass($connection, $customerClassId);
        $allowsSelection = (int) ($customerClass->CC_PermiteSeleccionar ?? 0) === 1;
        $enterpriseOperationalCenter = trim($enterpriseOperationalCenter);

        if ($this->shouldSkipEnterpriseOperationalCenter($enterpriseOperationalCenter)) {
            return new ReceiptCustomerSyncSnapshot(
                enterpriseOperationalCenter: $enterpriseOperationalCenter,
                thirdPartyId: $thirdPartyId,
                sourceBranch: $sourceBranch,
                customerClassId: $customerClassId,
                allowsSelection: $allowsSelection,
                canMigrate: false,
                shouldSync: false,
                skipReason: 'enterprise_operational_center_skipped',
            );
        }

        if (!$allowsSelection) {
            return new ReceiptCustomerSyncSnapshot(
                enterpriseOperationalCenter: $enterpriseOperationalCenter,
                thirdPartyId: $thirdPartyId,
                sourceBranch: $sourceBranch,
                customerClassId: $customerClassId,
                allowsSelection: false,
                canMigrate: false,
                shouldSync: false,
                skipReason: 'customer_class_disallows_selection',
            );
        }

        $canMigrate = $this->customerCanMigrate($connection, $thirdPartyId, $sourceBranch, $customerClassId);

        if (!$canMigrate) {
            return new ReceiptCustomerSyncSnapshot(
                enterpriseOperationalCenter: $enterpriseOperationalCenter,
                thirdPartyId: $thirdPartyId,
                sourceBranch: $sourceBranch,
                customerClassId: $customerClassId,
                allowsSelection: true,
                canMigrate: false,
                shouldSync: false,
                skipReason: 'customer_not_allowed_to_migrate',
            );
        }

        return new ReceiptCustomerSyncSnapshot(
            enterpriseOperationalCenter: $enterpriseOperationalCenter,
            thirdPartyId: $thirdPartyId,
            sourceBranch: $sourceBranch,
            customerClassId: $customerClassId,
            allowsSelection: true,
            canMigrate: true,
            shouldSync: true,
            skipReason: null,
            thirdPartyPrototype: $this->findThirdPartyPrototype($connection, $thirdPartyId, $sourceBranch),
            branchPrototype: $this->findBranchPrototype($connection, $thirdPartyId, $sourceBranch),
        );
    }

    private function customerCanMigrate(
        ConnectionInterface $connection,
        string $thirdPartyId,
        string $sourceBranch,
        string $customerClassId
    ): bool {
        $existingThirdParty = $connection->selectOne(
            sprintf(
                'SELECT TOP 1 f200_rowid AS rowid FROM %s WHERE RTRIM(f200_id) = ?',
                $this->enterpriseThirdPartiesTable()
            ),
            [$thirdPartyId]
        );

        if ($existingThirdParty !== null) {
            $existingClient = $connection->selectOne(
                sprintf(
                    'SELECT TOP 1 1 AS exists_flag FROM %s WHERE f201_rowid_tercero = ? AND RTRIM(f201_id_sucursal) = ?',
                    $this->enterpriseClientsTable()
                ),
                [
                    $existingThirdParty->rowid,
                    $this->expectedEnterpriseBranch($sourceBranch, $customerClassId),
                ]
            );

            return $existingClient === null;
        }

        $criteriaRow = $connection->selectOne(
            sprintf(
                "SELECT t206_SED.f206_id AS criterion_id
                FROM %s AS t200
                INNER JOIN %s AS t201 ON t200.f200_rowid = t201.f201_rowid_tercero
                LEFT JOIN %s AS t207_SED
                    ON t201.f201_rowid_tercero = t207_SED.f207_rowid_tercero
                    AND t201.f201_id_sucursal = t207_SED.f207_id_sucursal
                    AND t207_SED.f207_id_plan_criterios = 'SED'
                LEFT JOIN %s AS t206_SED
                    ON t207_SED.f207_id_cia = t206_SED.f206_id_cia
                    AND t207_SED.f207_id_plan_criterios = t206_SED.f206_id_plan
                    AND t207_SED.f207_id_criterio_mayor = t206_SED.f206_id
                WHERE RTRIM(t200.f200_id) = ?",
                $this->enterpriseThirdPartiesTable(),
                $this->enterpriseClientsTable(),
                $this->enterpriseClientCriteriaTable(),
                $this->enterpriseMajorCriteriaTable(),
            ),
            [$thirdPartyId]
        );

        if ($criteriaRow === null) {
            return true;
        }

        $criterionId = trim((string) ($criteriaRow->criterion_id ?? ''));

        if ($criterionId === '') {
            return true;
        }

        if ($criterionId === trim($customerClassId)) {
            return true;
        }

        $equivalentClasses = ['50', '5101', '52', '53'];

        return in_array($criterionId, $equivalentClasses, true)
            && in_array(trim($customerClassId), $equivalentClasses, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function findThirdPartyPrototype(ConnectionInterface $connection, string $thirdPartyId, string $sourceBranch): array
    {
        $row = $connection->selectOne(
            sprintf(
                "SELECT TOP 1
                    '0200' AS F_TIPO_REG,
                    '00' AS F_SUBTIPO_REG,
                    '02' AS F_VERSION_REG,
                    '001' AS F_CIA,
                    1 AS F_ACTUALIZA_REG,
                    RTRIM(c.CL_CodigoTercero) AS F200_ID,
                    RTRIM(ISNULL(c.CL_DocumentoTercero, c.CL_CodigoTercero)) AS F200_NIT,
                    RTRIM(c.CL_DV) AS F200_DV_NIT,
                    RTRIM(ti.Untipoid_sigla) AS F200_ID_TIPO_IDENT,
                    CASE WHEN RTRIM(ti.Untipoid_sigla) = 'N' THEN 2 ELSE 1 END AS F200_IND_TIPO_TERCERO,
                    RTRIM(c.CL_K3Nombre) AS F200_RAZON_SOCIAL,
                    RTRIM(c.CL_Apellido1) AS F200_APELLIDO1,
                    RTRIM(c.CL_Apellido2) AS F200_APELLIDO2,
                    RTRIM(c.CL_Nombre) AS F200_NOMBRES,
                    RTRIM(c.CL_K4Nombre) AS F200_NOMBRE_EST,
                    1 AS F200_IND_CLIENTE,
                    1 AS F200_IND_PROVEEDOR,
                    0 AS F200_IND_EMPLEADO,
                    0 AS F200_IND_ACCIONISTA,
                    0 AS F200_IND_OTROS,
                    0 AS F200_IND_INTERNO,
                    RTRIM(c.CL_K3Nombre) AS F015_CONTACTO,
                    SUBSTRING(REPLACE(COALESCE(c.CL_Direccion, ''), CHAR(10), ''), 1, 40) AS F015_DIRECCION1,
                    SUBSTRING(REPLACE(COALESCE(c.CL_Direccion, ''), CHAR(10), ''), 41, 40) AS F015_DIRECCION2,
                    SUBSTRING(REPLACE(COALESCE(c.CL_Direccion, ''), CHAR(10), ''), 81, 40) AS F015_DIRECCION3,
                    CASE WHEN SUBSTRING(c.CL_Ciudad, 1, 3) = '770' THEN '169' ELSE SUBSTRING(c.CL_Ciudad, 1, 3) END AS F015_ID_PAIS,
                    SUBSTRING(c.CL_Ciudad, 4, 2) AS F015_ID_DEPTO,
                    SUBSTRING(c.CL_Ciudad, 6, 3) AS F015_ID_CIUDAD,
                    '' AS F015_ID_BARRIO,
                    RTRIM(c.CL_Telefono1) AS F015_TELEFONO,
                    RTRIM(c.CL_Telefono2) AS F015_FAX,
                    '' AS F015_COD_POSTAL,
                    RTRIM(c.CL_Email) AS F015_EMAIL,
                    '20000101' AS F200_FECHA_NACIMIENTO,
                    '' AS F200_ID_CIIU
                FROM %s AS c
                INNER JOIN %s AS ti ON ti.Untipoid_k1_tipoid = c.CL_TipoIdentificacion
                WHERE c.CL_CodigoTercero = ? AND c.CL_Sucursal = ?",
                $this->customersTable(),
                $this->idTypesTable()
            ),
            [$thirdPartyId, $sourceBranch]
        );

        if (!$row instanceof stdClass) {
            throw new WorkerTaskProcessingException(
                sprintf('El cliente %s no tiene prototipo de tercero disponible.', $thirdPartyId),
                ['third_party_id' => $thirdPartyId, 'source_branch' => $sourceBranch]
            );
        }

        return (array) $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function findBranchPrototype(ConnectionInterface $connection, string $thirdPartyId, string $sourceBranch): array
    {
        $row = $connection->selectOne(
            sprintf(
                "SELECT TOP 1
                    c.CL_CodigoTercero AS F201_ID_TERCERO,
                    RIGHT('000' + CASE WHEN c.CL_Sucursal <> '00' THEN RTRIM(c.CL_Sucursal) ELSE '1' END, 3) AS F201_ID_SUCURSAL,
                    1 AS F201_IND_ESTADO_ACTIVO,
                    c.CL_K3Nombre AS F201_DESCRIPCION_SUCURSAL,
                    'COP' AS F201_ID_MONEDA,
                    '' AS F201_ID_VENDEDOR,
                    'C' AS F201_IND_CALIFICACION,
                    '001' AS F201_ID_COND_PAGO,
                    0 AS F201_DIAS_GRACIA,
                    1 AS F201_CUPO_CREDITO,
                    '' AS F201_ID_CLIENTE_CORP,
                    '' AS F201_ID_SUCURSAL_CORP,
                    RTRIM(etc.tipocliente) AS F201_ID_TIPO_CLI,
                    '' AS F201_ID_GRUPO_DSCTO,
                    'C01' AS F201_ID_LISTA_PRECIO,
                    '0' AS F201_IND_PEDIDO_BACKORDER,
                    0 AS F201_PORC_EXCESO_VENTA,
                    0 AS F201_PORC_MIN_MARGEN,
                    0 AS F201_PORC_MAX_MARGEN,
                    1 AS F201_IND_BLOQUEADO,
                    0 AS F201_IND_BLOQUEO_CUPO,
                    0 AS F201_IND_BLOQUEO_MORA,
                    0 AS F201_IND_FACTURA_UNIFICADA,
                    '' AS F201_ID_CO_FACTURA,
                    '' AS F201_NOTAS,
                    RTRIM(c.CL_K3Nombre) AS F015_CONTACTO,
                    SUBSTRING(REPLACE(COALESCE(c.CL_Direccion, ''), CHAR(10), ''), 1, 40) AS F015_DIRECCION1,
                    SUBSTRING(REPLACE(COALESCE(c.CL_Direccion, ''), CHAR(10), ''), 41, 40) AS F015_DIRECCION2,
                    SUBSTRING(REPLACE(COALESCE(c.CL_Direccion, ''), CHAR(10), ''), 81, 40) AS F015_DIRECCION3,
                    CASE WHEN SUBSTRING(c.CL_Ciudad, 1, 3) = '770' THEN '169' ELSE SUBSTRING(c.CL_Ciudad, 1, 3) END AS F015_ID_PAIS,
                    SUBSTRING(c.CL_Ciudad, 4, 2) AS F015_ID_DEPTO,
                    SUBSTRING(c.CL_Ciudad, 6, 3) AS F015_ID_CIUDAD,
                    '' AS F015_ID_BARRIO,
                    RTRIM(c.CL_Telefono1) AS F015_TELEFONO,
                    RTRIM(c.CL_Telefono2) AS F015_FAX,
                    '' AS F015_COD_POSTAL,
                    RTRIM(c.CL_Email) AS F015_EMAIL,
                    CONVERT(varchar(8), c.CL_FechaCreacion, 112) AS F201_FECHA_INGRESO,
                    '' AS F201_ID_CO_MOVTO_FACTURA,
                    '' AS F201_ID_UN_MOVTO_FACTURA,
                    '' AS F201_ID_PARAMETRO_EDI,
                    '' AS F201_CODIGO_EAN,
                    '' AS f201_fecha_cupo,
                    0 AS f201_porc_tolerancia,
                    0 AS f201_dia_maximo_factura,
                    CASE WHEN ica.CI_Id IS NULL THEN 0 ELSE 1 END AS IndicadorIca,
                    1 AS IndicadorINC,
                    RTRIM(mcc.CT_EquivalenciaEnterprise) AS CriterioClasificacionSEC,
                    ISNULL(ccg.CG_IdEquivalenciaEnterprise, ccg.CG_Id) AS CriterioClasificacionSED,
                    RTRIM(c.CL_TipoIdentificacion) AS Unterc_tipo_ident
                FROM %s AS c
                INNER JOIN %s AS ti ON ti.Untipoid_k1_tipoid = c.CL_TipoIdentificacion
                INNER JOIN %s AS cc ON cc.CC_Id = c.CL_ClaseDeCliente
                INNER JOIN %s AS etc ON etc.cuentauno85 = cc.cc_cuentaporcobrar
                LEFT JOIN %s AS ica ON ica.CI_Id = c.CL_Ciudad
                INNER JOIN %s AS ccg ON ccg.CG_ID = c.CL_ClaseDeCliente
                INNER JOIN %s AS mcc ON mcc.CT_ID = ccg.CG_CLA_CLI_FK
                WHERE c.CL_CodigoTercero = ? AND c.CL_Sucursal = ?",
                $this->customersTable(),
                $this->idTypesTable(),
                $this->customerClassesTable(),
                $this->typeEquivalencesTable(),
                $this->citiesIcaTable(),
                $this->customerClassGroupsTable(),
                $this->customerClassMasterTable()
            ),
            [$thirdPartyId, $sourceBranch]
        );

        if (!$row instanceof stdClass) {
            throw new WorkerTaskProcessingException(
                sprintf('El cliente %s no tiene prototipo de sucursal disponible.', $thirdPartyId),
                ['third_party_id' => $thirdPartyId, 'source_branch' => $sourceBranch]
            );
        }

        return (array) $row;
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
                'El payload de receipt_migration esta incompleto para sincronizar el tercero. Configura: ' . implode(', ', $missing) . '.',
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
                sprintf('No se pudo abrir la conexion de origen para sincronizar terceros de recibos: %s.', $connection),
                ['source_connection' => $sourceConnection, 'resolved_connection' => $connection]
            );
        }

        return $this->database->connection($connection);
    }

    private function shouldSkipEnterpriseOperationalCenter(string $enterpriseOperationalCenter): bool
    {
        return in_array($enterpriseOperationalCenter, $this->skipEnterpriseOperationalCenters(), true);
    }

    /**
     * @return list<string>
     */
    private function skipEnterpriseOperationalCenters(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) $this->config->get('workerhub.receipts.customer_sync.skip_enterprise_operational_centers', ['A40', 'A06'])
        )));
    }

    private function expectedEnterpriseBranch(string $sourceBranch, string $customerClassId): string
    {
        if (trim($sourceBranch) !== '06' && trim($customerClassId) !== '9004') {
            return '001';
        }

        return '006';
    }

    private function receiptTable(): string
    {
        return (string) $this->config->get('workerhub.receipts.customer_sync.tables.receipts', 'pos.recibos_encabezado');
    }

    private function customersTable(): string
    {
        return (string) $this->config->get('workerhub.receipts.customer_sync.tables.customers', 'pos.clientes');
    }

    private function customerClassesTable(): string
    {
        return (string) $this->config->get('workerhub.receipts.customer_sync.tables.customer_classes', 'pos.clase_de_cliente');
    }

    private function idTypesTable(): string
    {
        return (string) $this->config->get('workerhub.receipts.customer_sync.tables.id_types', 'maestros_uno.untipoid_catalogo_tipos_identificacion');
    }

    private function typeEquivalencesTable(): string
    {
        return (string) $this->config->get('workerhub.receipts.customer_sync.tables.type_equivalences', 'prototipos.equivalencia_tipo_cliente');
    }

    private function citiesIcaTable(): string
    {
        return (string) $this->config->get('workerhub.receipts.customer_sync.tables.cities_ica', 'prototipos.ciudades_ica');
    }

    private function customerClassGroupsTable(): string
    {
        return (string) $this->config->get('workerhub.receipts.customer_sync.tables.customer_class_groups', 'maestros.clase_cliente_grupo');
    }

    private function customerClassMasterTable(): string
    {
        return (string) $this->config->get('workerhub.receipts.customer_sync.tables.customer_class_master', 'maestros.clase_cliente');
    }

    private function enterpriseThirdPartiesTable(): string
    {
        return (string) $this->config->get('workerhub.receipts.customer_sync.enterprise_tables.third_parties', 'SiesaEnterprise.dbo.t200_mm_terceros');
    }

    private function enterpriseClientsTable(): string
    {
        return (string) $this->config->get('workerhub.receipts.customer_sync.enterprise_tables.clients', 'SiesaEnterprise.dbo.t201_mm_clientes');
    }

    private function enterpriseClientCriteriaTable(): string
    {
        return (string) $this->config->get('workerhub.receipts.customer_sync.enterprise_tables.client_criteria', 'SiesaEnterprise.dbo.t207_mm_criterios_clientes');
    }

    private function enterpriseMajorCriteriaTable(): string
    {
        return (string) $this->config->get('workerhub.receipts.customer_sync.enterprise_tables.major_criteria', 'SiesaEnterprise.dbo.t206_mm_criterios_mayores');
    }

    private function enterpriseBranchForSourceBranch(string $sourceBranch): string
    {
        $branch = trim($sourceBranch);

        if ($branch === '' || $branch === '00') {
            return '001';
        }

        return str_pad(ltrim($branch, '0') === '' ? '0' : ltrim($branch, '0'), 3, '0', STR_PAD_LEFT);
    }

    private function normalizeEnterpriseBranch(?string $branchHint): string
    {
        $branch = trim((string) $branchHint);

        if ($branch === '') {
            return '';
        }

        return str_pad(ltrim($branch, '0') === '' ? '0' : ltrim($branch, '0'), 3, '0', STR_PAD_LEFT);
    }

    private function normalizeSourceBranchHint(?string $branchHint): string
    {
        $branch = trim((string) $branchHint);

        if ($branch === '') {
            return '';
        }

        $normalizedEnterprise = $this->normalizeEnterpriseBranch($branch);

        if ($normalizedEnterprise === '001') {
            return '00';
        }

        return str_pad((string) ((int) $normalizedEnterprise), 2, '0', STR_PAD_LEFT);
    }
}
