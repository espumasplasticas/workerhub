<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\OrderMigrationController;
use App\Services\Workers\Orders\OrderLegacyStateService;
use App\Services\Workers\Orders\OrderPrototypeRepository;
use App\Services\Workers\Orders\OrderSiesaStateService;
use App\Services\Workers\WorkerTaskDispatchService;
use App\Services\Workers\WorkerTaskDispatchRegistryService;
use App\Services\Workers\WorkerTaskMonitorService;
use App\Support\WorkerTaskExecutionPlanResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class OrderMigrationControllerTest extends TestCase
{
    public function test_it_accepts_and_dispatches_an_order_migration_without_touching_the_database(): void
    {
        Str::createUuidsUsing(static fn () => '11111111-1111-1111-1111-111111111111');

        $resolver = Mockery::mock(WorkerTaskExecutionPlanResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->andReturn([
                'request_topic' => 'workerhub.tasks.requests',
                'process_key' => 'sales_orders',
                'process_label' => 'Pedidos',
            ]);

        $dispatcher = Mockery::mock(WorkerTaskDispatchService::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andReturn([
                'mode' => 'direct_queue',
                'queue' => 'sales-orders-default',
            ]);

        $registry = Mockery::mock(WorkerTaskDispatchRegistryService::class);
        $registry->shouldReceive('findAccepted')->once()->with('order', '002-FC-24116')->andReturnNull();
        $registry->shouldReceive('recordAcceptedForTask')->once()->with('order_migration', Mockery::type('array'), '11111111-1111-1111-1111-111111111111');

        $monitor = Mockery::mock(WorkerTaskMonitorService::class);
        $monitor->shouldReceive('createTask')->once();
        $monitor->shouldReceive('markQueued')->once()->with('11111111-1111-1111-1111-111111111111', 'sales-orders-default');

        $repository = Mockery::mock(OrderPrototypeRepository::class);
        $repository->shouldReceive('hydratePayloadFromOrderId')->once()->andReturn([
            'order_id' => 1411395,
            'document_id' => '002-FC-24116',
            'db_connection' => 'sqlsrv',
            'operational_center' => '002',
            'document_type' => 'FC',
            'document_number' => '24116',
            'company_id' => 2,
            'client_code' => '900123',
            'client_branch' => '001',
            'source' => 'api',
            'metadata' => [
                'process_key' => 'sales_orders',
                'process_label' => 'Pedidos',
                'schedule_name' => 'API_ORDER_CREATED',
                'task_name' => 'Migracion pedido POS',
            ],
        ]);
        $repository->shouldReceive('findHeader')->once()->andReturn((object) [
            'f430_id_co' => '002',
            'f430_id_tipo_docto' => 'PFC',
            'f430_consec_docto' => '24116',
        ]);

        $siesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $siesaStateService->shouldReceive('fetch')
            ->once()
            ->andReturn(new \App\Data\Orders\OrderSiesaStateSnapshot('002', 'FC', '24116', false));

        $legacyState = Mockery::mock(OrderLegacyStateService::class);
        $legacyState->shouldNotReceive('markDetectedInSiesa');

        $controller = new OrderMigrationController(
            $resolver,
            $dispatcher,
            $registry,
            $monitor,
            $repository,
            $siesaStateService,
            $legacyState
        );

        $request = Request::create('/api/order-migrations', 'POST', [
            'order_id' => 1411395,
            'db_connection' => 'sqlsrv',
            'company_id' => 2,
            'source' => 'api',
            'process_key' => 'sales_orders',
            'process_label' => 'Pedidos',
            'schedule_name' => 'API_ORDER_CREATED',
            'task_name' => 'Migracion pedido POS',
        ]);

        $response = $controller->store($request);

        $this->assertSame(202, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertTrue($payload['accepted']);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $payload['task_id']);
        $this->assertSame('sales-orders-default', $payload['queue']);
        $this->assertSame('direct_queue', $payload['dispatch_mode']);

        Str::createUuidsNormally();
    }

    public function test_it_rejects_enqueue_when_the_order_already_exists_in_siesa(): void
    {
        $resolver = Mockery::mock(WorkerTaskExecutionPlanResolver::class);
        $resolver->shouldNotReceive('resolve');

        $dispatcher = Mockery::mock(WorkerTaskDispatchService::class);
        $dispatcher->shouldNotReceive('dispatch');

        $registry = Mockery::mock(WorkerTaskDispatchRegistryService::class);
        $registry->shouldReceive('findAccepted')->once()->with('order', '002-FC-24116')->andReturnNull();
        $registry->shouldNotReceive('recordAcceptedForTask');

        $monitor = Mockery::mock(WorkerTaskMonitorService::class);
        $monitor->shouldNotReceive('createTask');
        $monitor->shouldNotReceive('markQueued');

        $repository = Mockery::mock(OrderPrototypeRepository::class);
        $repository->shouldReceive('hydratePayloadFromOrderId')->once()->andReturn([
            'order_id' => 1411395,
            'document_id' => '002-FC-24116',
            'db_connection' => 'sqlsrv',
            'operational_center' => '002',
            'document_type' => 'FC',
            'document_number' => '24116',
            'source' => 'api',
        ]);
        $repository->shouldReceive('findHeader')->once()->andReturn((object) [
            'f430_id_co' => '002',
            'f430_id_tipo_docto' => 'PFC',
            'f430_consec_docto' => '24116',
        ]);

        $siesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $siesaStateService->shouldReceive('fetch')
            ->once()
            ->andReturn(new \App\Data\Orders\OrderSiesaStateSnapshot('002', 'FC', '24116', true, '002', 'PFC', '24116'));

        $legacyState = Mockery::mock(OrderLegacyStateService::class);
        $legacyState->shouldReceive('markDetectedInSiesa')->once();

        $controller = new OrderMigrationController(
            $resolver,
            $dispatcher,
            $registry,
            $monitor,
            $repository,
            $siesaStateService,
            $legacyState
        );

        $request = Request::create('/api/order-migrations', 'POST', [
            'order_id' => 1411395,
            'db_connection' => 'sqlsrv',
            'source' => 'api',
        ]);

        $response = $controller->store($request);

        $this->assertSame(409, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertFalse($payload['accepted']);
        $this->assertTrue($payload['siesa_state']['exists']);
    }

    public function test_it_returns_the_existing_accepted_dispatch_without_reenqueuing(): void
    {
        $resolver = Mockery::mock(WorkerTaskExecutionPlanResolver::class);
        $resolver->shouldNotReceive('resolve');

        $dispatcher = Mockery::mock(WorkerTaskDispatchService::class);
        $dispatcher->shouldNotReceive('dispatch');

        $registryRecord = new \App\Models\WorkerTaskDispatchRegistry([
            'task_id' => 'existing-order-task',
            'accepted_at' => now(),
        ]);

        $registry = Mockery::mock(WorkerTaskDispatchRegistryService::class);
        $registry->shouldReceive('findAccepted')->once()->with('order', '002-FC-24116')->andReturn($registryRecord);
        $registry->shouldNotReceive('recordAcceptedForTask');

        $monitor = Mockery::mock(WorkerTaskMonitorService::class);
        $monitor->shouldNotReceive('createTask');

        $repository = Mockery::mock(OrderPrototypeRepository::class);
        $repository->shouldReceive('hydratePayloadFromOrderId')->once()->andReturn([
            'order_id' => 1411395,
            'document_id' => '002-FC-24116',
            'db_connection' => 'sqlsrv',
            'operational_center' => '002',
            'document_type' => 'FC',
            'document_number' => '24116',
            'source' => 'api',
        ]);
        $repository->shouldNotReceive('findHeader');

        $siesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $siesaStateService->shouldNotReceive('fetch');

        $legacyState = Mockery::mock(OrderLegacyStateService::class);
        $legacyState->shouldNotReceive('markDetectedInSiesa');

        $controller = new OrderMigrationController(
            $resolver,
            $dispatcher,
            $registry,
            $monitor,
            $repository,
            $siesaStateService,
            $legacyState
        );

        $request = Request::create('/api/order-migrations', 'POST', [
            'order_id' => 1411395,
            'db_connection' => 'sqlsrv',
            'source' => 'api',
        ]);

        $response = $controller->store($request);

        $this->assertSame(202, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertTrue($payload['accepted']);
        $this->assertTrue($payload['duplicate']);
        $this->assertSame('existing-order-task', $payload['task_id']);
    }
}
