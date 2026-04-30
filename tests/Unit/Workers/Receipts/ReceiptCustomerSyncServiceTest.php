<?php

namespace Tests\Unit\Workers\Receipts;

use App\Contracts\ReceiptCustomerSyncDataSourceInterface;
use App\Data\Receipts\ReceiptCustomerSyncSnapshot;
use App\Services\Workers\Receipts\ReceiptCustomerSyncLineFactory;
use App\Services\Workers\Receipts\ReceiptCustomerSyncService;
use Mockery;
use Tests\TestCase;

class ReceiptCustomerSyncServiceTest extends TestCase
{
    public function test_it_skips_customer_sync_when_the_snapshot_does_not_require_it(): void
    {
        config()->set('workerhub.receipts.customer_sync.enabled', true);

        $dataSource = Mockery::mock(ReceiptCustomerSyncDataSourceInterface::class);
        $dataSource->shouldReceive('fetch')
            ->once()
            ->andReturn(new ReceiptCustomerSyncSnapshot(
                enterpriseOperationalCenter: 'A40',
                thirdPartyId: '900123',
                sourceBranch: '00',
                customerClassId: '50',
                allowsSelection: true,
                canMigrate: false,
                shouldSync: false,
                skipReason: 'enterprise_operational_center_skipped',
            ));

        $service = new ReceiptCustomerSyncService(
            $dataSource,
            new ReceiptCustomerSyncLineFactory(),
            app('config')
        );

        $result = $service->sync(
            ['document_id' => '002-FC-24088'],
            (object) ['F350_ID_CO' => 'A40']
        );

        $this->assertSame('skipped', $result['status']);
        $this->assertSame(0, $result['line_count']);
        $this->assertSame([], $result['lines']);
        $this->assertSame('enterprise_operational_center_skipped', $result['parties'][0]['snapshot']['skip_reason']);
    }

    public function test_it_prepares_customer_lines_before_the_receipt_when_required(): void
    {
        config()->set('workerhub.receipts.customer_sync.enabled', true);

        $snapshot = new ReceiptCustomerSyncSnapshot(
            enterpriseOperationalCenter: '001',
            thirdPartyId: '900123',
            sourceBranch: '00',
            customerClassId: '50',
            allowsSelection: true,
            canMigrate: true,
            shouldSync: true,
            skipReason: null,
            thirdPartyPrototype: $this->thirdPartyPrototype(),
            branchPrototype: $this->branchPrototype(),
        );

        $dataSource = Mockery::mock(ReceiptCustomerSyncDataSourceInterface::class);
        $dataSource->shouldReceive('fetch')
            ->once()
            ->andReturn($snapshot);

        $service = new ReceiptCustomerSyncService(
            $dataSource,
            new ReceiptCustomerSyncLineFactory(),
            app('config')
        );

        $result = $service->sync(
            ['document_id' => '002-FC-24088'],
            (object) ['F350_ID_CO' => '001']
        );

        $this->assertSame('prepared', $result['status']);
        $this->assertSame(12, $result['line_count']);
        $this->assertCount(12, $result['lines']);
        $this->assertSame('receipt_customer', $result['parties'][0]['role']);
        $this->assertSame('prepared', $result['parties'][0]['status']);
        $this->assertTrue(str_starts_with($result['lines'][0], '0200'));
        $this->assertTrue(str_starts_with($result['lines'][1], '0201'));
        $this->assertTrue(str_starts_with($result['lines'][10], '0207'));
    }

    public function test_it_prepares_the_other_income_third_party_when_receipt_header_references_a_different_party(): void
    {
        config()->set('workerhub.receipts.customer_sync.enabled', true);

        $primarySnapshot = new ReceiptCustomerSyncSnapshot(
            enterpriseOperationalCenter: '001',
            thirdPartyId: '900123',
            sourceBranch: '00',
            customerClassId: '50',
            allowsSelection: true,
            canMigrate: false,
            shouldSync: false,
            skipReason: 'customer_not_allowed_to_migrate',
        );

        $dependentSnapshot = new ReceiptCustomerSyncSnapshot(
            enterpriseOperationalCenter: '001',
            thirdPartyId: '12364578',
            sourceBranch: '00',
            customerClassId: '50',
            allowsSelection: true,
            canMigrate: true,
            shouldSync: true,
            skipReason: null,
            thirdPartyPrototype: $this->thirdPartyPrototype('12364578'),
            branchPrototype: $this->branchPrototype('12364578'),
        );

        $dataSource = Mockery::mock(ReceiptCustomerSyncDataSourceInterface::class);
        $dataSource->shouldReceive('fetch')
            ->once()
            ->andReturn($primarySnapshot);
        $dataSource->shouldReceive('fetchThirdParty')
            ->once()
            ->withArgs(function (array $payload, string $thirdPartyId, ?string $branchHint, string $enterpriseCo): bool {
                return $payload['document_id'] === '002-FC-24089'
                    && $thirdPartyId === '12364578'
                    && $branchHint === '001'
                    && $enterpriseCo === '001';
            })
            ->andReturn($dependentSnapshot);

        $service = new ReceiptCustomerSyncService(
            $dataSource,
            new ReceiptCustomerSyncLineFactory(),
            app('config')
        );

        $result = $service->sync(
            ['document_id' => '002-FC-24089'],
            (object) [
                'F350_ID_CO' => '001',
                'F350_ID_TERCERO' => '900123',
                'F351_ID_TERCERO_OTRO_ING' => '12364578',
                'F351_ID_SUCURSAL_OTRO_ING' => '001',
            ]
        );

        $this->assertSame('prepared', $result['status']);
        $this->assertSame(12, $result['line_count']);
        $this->assertCount(12, $result['lines']);
        $this->assertCount(2, $result['parties']);
        $this->assertSame('skipped', $result['parties'][0]['status']);
        $this->assertSame('other_income_third_party', $result['parties'][1]['role']);
        $this->assertSame('prepared', $result['parties'][1]['status']);
        $this->assertSame('12364578', $result['parties'][1]['snapshot']['third_party_id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function thirdPartyPrototype(string $thirdPartyId = '900123'): array
    {
        return [
            'F200_ID' => $thirdPartyId,
            'F200_NIT' => $thirdPartyId,
            'F200_DV_NIT' => '1',
            'F200_ID_TIPO_IDENT' => 'N',
            'F200_IND_TIPO_TERCERO' => 2,
            'F200_RAZON_SOCIAL' => 'CLIENTE PRUEBA COMODISIMOS',
            'F200_APELLIDO1' => '',
            'F200_APELLIDO2' => '',
            'F200_NOMBRES' => '',
            'F200_NOMBRE_EST' => 'CLIENTE PRUEBA',
            'F200_IND_CLIENTE' => 1,
            'F200_IND_PROVEEDOR' => 1,
            'F200_IND_EMPLEADO' => 0,
            'F200_IND_ACCIONISTA' => 0,
            'F200_IND_OTROS' => 0,
            'F200_IND_INTERNO' => 0,
            'F015_CONTACTO' => 'CLIENTE PRUEBA COMODISIMOS',
            'F015_DIRECCION1' => 'CALLE 1 # 2 - 3',
            'F015_DIRECCION2' => '',
            'F015_DIRECCION3' => '',
            'F015_ID_PAIS' => '169',
            'F015_ID_DEPTO' => '05',
            'F015_ID_CIUDAD' => '001',
            'F015_ID_BARRIO' => '',
            'F015_TELEFONO' => '3000000000',
            'F015_FAX' => '',
            'F015_COD_POSTAL' => '',
            'F015_EMAIL' => 'cliente@example.com',
            'F200_FECHA_NACIMIENTO' => '20000101',
            'F200_ID_CIIU' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function branchPrototype(string $thirdPartyId = '900123'): array
    {
        return [
            'F201_ID_TERCERO' => $thirdPartyId,
            'F201_ID_SUCURSAL' => '001',
            'F201_IND_ESTADO_ACTIVO' => 1,
            'F201_DESCRIPCION_SUCURSAL' => 'CASA MATRIZ',
            'F201_ID_MONEDA' => 'COP',
            'F201_ID_VENDEDOR' => '',
            'F201_IND_CALIFICACION' => 'C',
            'F201_ID_COND_PAGO' => '001',
            'F201_DIAS_GRACIA' => 0,
            'F201_CUPO_CREDITO' => 1,
            'F201_ID_CLIENTE_CORP' => '',
            'F201_ID_SUCURSAL_CORP' => '',
            'F201_ID_TIPO_CLI' => 'CONT',
            'F201_ID_GRUPO_DSCTO' => '',
            'F201_ID_LISTA_PRECIO' => 'C01',
            'F201_IND_PEDIDO_BACKORDER' => '0',
            'F201_PORC_EXCESO_VENTA' => 0,
            'F201_PORC_MIN_MARGEN' => 0,
            'F201_PORC_MAX_MARGEN' => 0,
            'F201_IND_BLOQUEADO' => 1,
            'F201_IND_BLOQUEO_CUPO' => 0,
            'F201_IND_BLOQUEO_MORA' => 0,
            'F201_IND_FACTURA_UNIFICADA' => 0,
            'F201_ID_CO_FACTURA' => '',
            'F201_NOTAS' => '',
            'F015_CONTACTO' => 'CLIENTE PRUEBA COMODISIMOS',
            'F015_DIRECCION1' => 'CALLE 1 # 2 - 3',
            'F015_DIRECCION2' => '',
            'F015_DIRECCION3' => '',
            'F015_ID_PAIS' => '169',
            'F015_ID_DEPTO' => '05',
            'F015_ID_CIUDAD' => '001',
            'F015_ID_BARRIO' => '',
            'F015_TELEFONO' => '3000000000',
            'F015_FAX' => '',
            'F015_COD_POSTAL' => '',
            'F015_EMAIL' => 'cliente@example.com',
            'F201_FECHA_INGRESO' => '20260415',
            'F201_ID_CO_MOVTO_FACTURA' => '',
            'F201_ID_UN_MOVTO_FACTURA' => '',
            'F201_ID_PARAMETRO_EDI' => '',
            'F201_CODIGO_EAN' => '',
            'f201_fecha_cupo' => '',
            'f201_porc_tolerancia' => 0,
            'f201_dia_maximo_factura' => 0,
            'IndicadorIca' => 1,
            'IndicadorINC' => 1,
            'CriterioClasificacionSEC' => 'SEC001',
            'CriterioClasificacionSED' => 'SED001',
            'Unterc_tipo_ident' => 2,
        ];
    }
}
