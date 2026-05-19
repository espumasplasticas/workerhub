<?php

namespace Tests\Unit;

use App\Services\Workers\Orders\OrderSiesaStateService;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Mockery;
use stdClass;
use Tests\TestCase;

class OrderSiesaStateServiceTest extends TestCase
{
    public function test_it_reads_the_enterprise_net_total_from_t431_detail_rows(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('getPdo')->once()->andReturn(new stdClass());
        $connection->shouldReceive('selectOne')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                return str_contains($sql, 'FROM SiesaEnterprise.dbo.t430_cm_pv_docto')
                    && !str_contains($sql, 'f430_valor_neto')
                    && $bindings === ['002', 'PFC', '22846'];
            })
            ->andReturn((object) [
                'f430_id_co' => '002',
                'f430_id_tipo_docto' => 'PFC',
                'f430_consec_docto' => '22846',
                'f430_rowid' => 1234,
                'f430_ind_estado' => 1,
            ]);
        $connection->shouldReceive('selectOne')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                return str_contains($sql, 'FROM SiesaEnterprise.dbo.t431_cm_pv_movto')
                    && str_contains($sql, 'SUM(f431_vlr_neto)')
                    && $bindings === [1234];
            })
            ->andReturn((object) ['net_total' => '157500.0000']);

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->twice()->with('source_test')->andReturn($connection);

        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')->with('workerhub.orders.source_connections', [])->andReturn([
            'test' => 'source_test',
        ]);
        $config->shouldReceive('get')->with('workerhub.orders.enterprise_state.tables.orders', 'SiesaEnterprise.dbo.t430_cm_pv_docto')
            ->andReturn('SiesaEnterprise.dbo.t430_cm_pv_docto');
        $config->shouldReceive('get')->with('workerhub.orders.enterprise_state.tables.order_lines', 'SiesaEnterprise.dbo.t431_cm_pv_movto')
            ->andReturn('SiesaEnterprise.dbo.t431_cm_pv_movto');

        $service = new OrderSiesaStateService($database, $config);

        $snapshot = $service->fetch([
            'db_connection' => 'test',
            'operational_center' => '002',
            'document_type' => 'FC',
            'document_number' => '22846',
        ], (object) [
            'f430_id_co' => '002',
            'f430_id_tipo_docto' => 'PFC',
            'f430_consec_docto' => '22846',
        ]);

        $this->assertTrue($snapshot->exists);
        $this->assertSame(1234, $snapshot->rowId);
        $this->assertSame(157500.0, $snapshot->netTotal);
    }

    public function test_it_falls_back_to_purchase_order_reference_when_primary_order_lookup_fails(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('getPdo')->once()->andReturn(new stdClass());
        $connection->shouldReceive('selectOne')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                return str_contains($sql, 'FROM SiesaEnterprise.dbo.t430_cm_pv_docto')
                    && str_contains($sql, 'RTRIM(f430_id_co) = ?')
                    && !str_contains($sql, 'f430_num_docto_referencia')
                    && $bindings === ['002', 'PFC', '22846'];
            })
            ->andReturn(null);
        $connection->shouldReceive('selectOne')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                return str_contains($sql, 'FROM SiesaEnterprise.dbo.t430_cm_pv_docto')
                    && str_contains($sql, 'f430_num_docto_referencia')
                    && str_contains($sql, 'f430_referencia')
                    && $bindings === ['OC-24116', 'LOAD-24116', '002'];
            })
            ->andReturn((object) [
                'f430_id_co' => '002',
                'f430_id_tipo_docto' => 'PFC',
                'f430_consec_docto' => '24116',
                'f430_rowid' => 5678,
                'f430_ind_estado' => 1,
            ]);
        $connection->shouldReceive('selectOne')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                return str_contains($sql, 'FROM SiesaEnterprise.dbo.t431_cm_pv_movto')
                    && $bindings === [5678];
            })
            ->andReturn((object) ['net_total' => '84000.0000']);

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->twice()->with('source_test')->andReturn($connection);

        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')->with('workerhub.orders.source_connections', [])->andReturn([
            'test' => 'source_test',
        ]);
        $config->shouldReceive('get')->with('workerhub.orders.enterprise_state.tables.orders', 'SiesaEnterprise.dbo.t430_cm_pv_docto')
            ->andReturn('SiesaEnterprise.dbo.t430_cm_pv_docto');
        $config->shouldReceive('get')->with('workerhub.orders.enterprise_state.tables.order_lines', 'SiesaEnterprise.dbo.t431_cm_pv_movto')
            ->andReturn('SiesaEnterprise.dbo.t431_cm_pv_movto');

        $service = new OrderSiesaStateService($database, $config);

        $snapshot = $service->fetch([
            'db_connection' => 'test',
            'operational_center' => '002',
            'document_type' => 'FC',
            'document_number' => '24116',
        ], (object) [
            'f430_id_co' => '002',
            'f430_id_tipo_docto' => 'PFC',
            'f430_consec_docto' => '24116',
            'f430_num_docto_referencia' => 'OC-24116',
            'f430_referencia' => 'LOAD-24116',
        ]);

        $this->assertTrue($snapshot->exists);
        $this->assertSame(5678, $snapshot->rowId);
        $this->assertSame(84000.0, $snapshot->netTotal);
    }
}
