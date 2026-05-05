<?php

namespace Tests\Unit;

use App\Services\Workers\Invoices\InvoiceHeaderPreparationService;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Mockery;
use stdClass;
use Tests\TestCase;

class InvoiceHeaderPreparationServiceTest extends TestCase
{
    public function test_it_fills_receivable_and_customer_class_when_the_invoice_is_missing_them(): void
    {
        $customerQuery = Mockery::mock(Builder::class);
        $customerQuery->shouldReceive('select')->once()->with(['CL_ClaseDeCliente'])->andReturnSelf();
        $customerQuery->shouldReceive('where')->once()->with('CL_CodigoTercero', '900555')->andReturnSelf();
        $customerQuery->shouldReceive('when')->once()->andReturnUsing(function ($condition, $callback) use ($customerQuery) {
            if ($condition) {
                $callback($customerQuery);
            }

            return $customerQuery;
        });
        $customerQuery->shouldReceive('where')->once()->with('CL_Sucursal', '01')->andReturnSelf();
        $customerQuery->shouldReceive('first')->once()->andReturn((object) ['CL_ClaseDeCliente' => '52']);

        $customerClassQuery = Mockery::mock(Builder::class);
        $customerClassQuery->shouldReceive('select')->once()->with(['CC_CuentaPorCobrar'])->andReturnSelf();
        $customerClassQuery->shouldReceive('where')->once()->with('CC_Id', '52')->andReturnSelf();
        $customerClassQuery->shouldReceive('first')->once()->andReturn((object) ['CC_CuentaPorCobrar' => '13050515']);

        $invoiceQuery = Mockery::mock(Builder::class);
        $invoiceQuery->shouldReceive('where')->once()->with('FE_CentroOperativo', '002')->andReturnSelf();
        $invoiceQuery->shouldReceive('where')->once()->with('FE_TipoDocumento', 'F4')->andReturnSelf();
        $invoiceQuery->shouldReceive('where')->once()->with('FE_NumeroDocumento', '24787')->andReturnSelf();
        $invoiceQuery->shouldReceive('update')->once()->with([
            'FE_CuentaPorCobrar' => '13050515',
            'FE_ClaseDeCliente' => '52',
        ])->andReturn(1);

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->once()->with('pos.clientes')->andReturn($customerQuery);
        $connection->shouldReceive('table')->once()->with('pos.clase_de_cliente')->andReturn($customerClassQuery);
        $connection->shouldReceive('table')->once()->with('pos.facturas_encabezado')->andReturn($invoiceQuery);

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->with('source_test')->andReturn($connection);

        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')->with('workerhub.invoices.source_connections.test', 'source_sqlsrv')->andReturn('source_test');
        $config->shouldReceive('get')->with('workerhub.invoices.tables.customers', 'pos.clientes')->andReturn('pos.clientes');
        $config->shouldReceive('get')->with('workerhub.invoices.tables.customer_classes', 'pos.clase_de_cliente')->andReturn('pos.clase_de_cliente');
        $config->shouldReceive('get')->with('workerhub.invoices.tables.invoices', 'pos.facturas_encabezado')->andReturn('pos.facturas_encabezado');

        $service = new InvoiceHeaderPreparationService($database, $config);

        $result = $service->ensureReceivableAndCustomerClassArePresent(
            ['db_connection' => 'test'],
            (object) [
                'FE_CentroOperativo' => '002',
                'FE_TipoDocumento' => 'F4',
                'FE_NumeroDocumento' => '24787',
                'FE_CodigoTercero' => '900555',
                'FE_CodigoSucursal' => '01',
                'FE_CuentaPorCobrar' => '',
            ]
        );

        $this->assertTrue($result);
    }

    public function test_it_skips_when_the_invoice_already_has_a_receivable_account(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $config = Mockery::mock(Repository::class);

        $service = new InvoiceHeaderPreparationService($database, $config);

        $result = $service->ensureReceivableAndCustomerClassArePresent(
            ['db_connection' => 'test'],
            (object) ['FE_CuentaPorCobrar' => '13050515']
        );

        $this->assertFalse($result);
    }
}
