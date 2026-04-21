<?php

namespace Tests\Unit\Orders;

use App\Services\Workers\Orders\OrderCashConversionService;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Mockery;
use stdClass;
use Tests\TestCase;

class OrderCashConversionServiceTest extends TestCase
{
    public function test_it_normalizes_credit_order_to_cash_when_supported_payments_cover_the_sale(): void
    {
        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')->with('workerhub.orders.source_connections', [])->andReturn(['test' => 'source_test']);
        $config->shouldReceive('get')->with('workerhub.orders.cash_conversion.cash_registers_table', 'SiesaEnterprise.dbo.t291_co_cajas')->andReturn('SiesaEnterprise.dbo.t291_co_cajas');
        $config->shouldReceive('get')->with('workerhub.orders.cash_conversion.fast_supported_amount_query', true)->andReturn(true);
        $config->shouldReceive('get')->with('workerhub.orders.cash_conversion.supported_payments_procedure', 'ventas.usp_obtener_medidos_pago_del_valor_que_soporta_la_venta_V2')->andReturn('ventas.usp_obtener_medidos_pago_del_valor_que_soporta_la_venta_V2');
        $config->shouldReceive('get')->with('workerhub.orders.tables.orders', 'pos.pedidos_encabezado')->andReturn('pos.pedidos_encabezado');

        $cashRegistersBuilder = Mockery::mock(Builder::class);
        $cashRegistersBuilder->shouldReceive('where')->with('f291_id_co', 'A41')->andReturnSelf();
        $cashRegistersBuilder->shouldReceive('where')->with('f291_id', '999')->andReturnSelf();
        $cashRegistersBuilder->shouldReceive('where')->with('f291_id_cia', 1)->andReturnSelf();
        $cashRegistersBuilder->shouldReceive('count')->once()->andReturn(1);

        $ordersBuilder = Mockery::mock(Builder::class);
        $ordersBuilder->shouldReceive('where')->with('PE_CentroOperativo', '002')->andReturnSelf();
        $ordersBuilder->shouldReceive('where')->with('PE_TipoDocumento', 'FC')->andReturnSelf();
        $ordersBuilder->shouldReceive('where')->with('PE_NumeroDocumento', '22847')->andReturnSelf();
        $ordersBuilder->shouldReceive('update')->once()->with([
            'PE_FormaDePago' => 0,
            'PE_CondicionDePago' => '0',
        ])->andReturn(1);

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->with('SiesaEnterprise.dbo.t291_co_cajas')->andReturn($cashRegistersBuilder);
        $connection->shouldReceive('selectOne')->once()->with(Mockery::type('string'), ['98701987', '1'])
            ->andReturn((object) ['total_supported_amount' => 163000]);
        $connection->shouldReceive('table')->with('pos.pedidos_encabezado')->andReturn($ordersBuilder);

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->with('source_test')->andReturn($connection);

        $service = new OrderCashConversionService($database, $config);

        $result = $service->normalizeIfSupported([
            'db_connection' => 'test',
            'operational_center' => '002',
            'document_type' => 'FC',
            'document_number' => '22847',
        ], (object) [
            'f430_id_cond_pago' => '001',
            'SA_CentroOperativoEnterprise' => 'A41',
            'f430_id_tercero_fact' => '98701987',
            'f430_id_sucursal_fact' => '1',
            'PE_TotalNeto' => 163000,
        ]);

        $this->assertTrue($result);
    }

    public function test_it_falls_back_to_stored_procedure_when_fast_query_returns_zero(): void
    {
        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')->with('workerhub.orders.source_connections', [])->andReturn(['test' => 'source_test']);
        $config->shouldReceive('get')->with('workerhub.orders.cash_conversion.cash_registers_table', 'SiesaEnterprise.dbo.t291_co_cajas')->andReturn('SiesaEnterprise.dbo.t291_co_cajas');
        $config->shouldReceive('get')->with('workerhub.orders.cash_conversion.fast_supported_amount_query', true)->andReturn(true);
        $config->shouldReceive('get')->with('workerhub.orders.cash_conversion.supported_payments_procedure', 'ventas.usp_obtener_medidos_pago_del_valor_que_soporta_la_venta_V2')->andReturn('ventas.usp_obtener_medidos_pago_del_valor_que_soporta_la_venta_V2');
        $config->shouldReceive('get')->with('workerhub.orders.tables.orders', 'pos.pedidos_encabezado')->andReturn('pos.pedidos_encabezado');

        $cashRegistersBuilder = Mockery::mock(Builder::class);
        $cashRegistersBuilder->shouldReceive('where')->with('f291_id_co', 'A41')->andReturnSelf();
        $cashRegistersBuilder->shouldReceive('where')->with('f291_id', '999')->andReturnSelf();
        $cashRegistersBuilder->shouldReceive('where')->with('f291_id_cia', 1)->andReturnSelf();
        $cashRegistersBuilder->shouldReceive('count')->once()->andReturn(1);

        $ordersBuilder = Mockery::mock(Builder::class);
        $ordersBuilder->shouldReceive('where')->with('PE_CentroOperativo', '002')->andReturnSelf();
        $ordersBuilder->shouldReceive('where')->with('PE_TipoDocumento', 'FC')->andReturnSelf();
        $ordersBuilder->shouldReceive('where')->with('PE_NumeroDocumento', '22847')->andReturnSelf();
        $ordersBuilder->shouldReceive('update')->once()->with([
            'PE_FormaDePago' => 0,
            'PE_CondicionDePago' => '0',
        ])->andReturn(1);

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->with('SiesaEnterprise.dbo.t291_co_cajas')->andReturn($cashRegistersBuilder);
        $connection->shouldReceive('selectOne')->once()->with(Mockery::type('string'), ['98701987', '1'])
            ->andReturn((object) ['total_supported_amount' => 0]);
        $connection->shouldReceive('select')->once()->with('EXEC ventas.usp_obtener_medidos_pago_del_valor_que_soporta_la_venta_V2 ?, ?', ['98701987', '1'])
            ->andReturn([(object) ['valor_saldo_cruzar' => 163000]]);
        $connection->shouldReceive('table')->with('pos.pedidos_encabezado')->andReturn($ordersBuilder);

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->with('source_test')->andReturn($connection);

        $service = new OrderCashConversionService($database, $config);

        $result = $service->normalizeIfSupported([
            'db_connection' => 'test',
            'operational_center' => '002',
            'document_type' => 'FC',
            'document_number' => '22847',
        ], (object) [
            'f430_id_cond_pago' => '001',
            'SA_CentroOperativoEnterprise' => 'A41',
            'f430_id_tercero_fact' => '98701987',
            'f430_id_sucursal_fact' => '1',
            'PE_TotalNeto' => 163000,
        ]);

        $this->assertTrue($result);
    }

    public function test_it_skips_conversion_when_header_is_not_credit(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $config = Mockery::mock(Repository::class);

        $service = new OrderCashConversionService($database, $config);

        $result = $service->normalizeIfSupported([], (object) [
            'f430_id_cond_pago' => '000',
        ]);

        $this->assertFalse($result);
    }
}
