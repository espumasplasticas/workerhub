<?php

namespace Tests\Unit\Workers\Receipts;

use App\Data\Receipts\ReceiptCustomerSyncSnapshot;
use App\Services\Workers\Receipts\ReceiptCustomerSyncLineFactory;
use Tests\TestCase;

class ReceiptCustomerSyncLineFactoryTest extends TestCase
{
    public function test_it_builds_the_expected_customer_sync_lines(): void
    {
        $factory = new ReceiptCustomerSyncLineFactory();

        $lines = $factory->build(new ReceiptCustomerSyncSnapshot(
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
        ));

        $this->assertCount(12, $lines);
        $this->assertSame(['0200', '0201', '0046', '0046', '0046', '0047', '0047', '0047', '0047', '0047', '0207', '0207'], array_map(
            static fn (string $line): string => substr($line, 0, 4),
            $lines
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function thirdPartyPrototype(): array
    {
        return [
            'F200_ID' => '900123',
            'F200_NIT' => '900123',
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
    private function branchPrototype(): array
    {
        return [
            'F201_ID_TERCERO' => '900123',
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
