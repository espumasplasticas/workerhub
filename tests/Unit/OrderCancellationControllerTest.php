<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\OrderCancellationController;
use App\Services\Workers\Orders\OrderLegacyStateService;
use App\Services\Workers\Orders\OrderPrototypeRepository;
use App\Services\Workers\Orders\OrderSiesaStateService;
use App\Services\Workers\WorkerTaskDispatchService;
use App\Services\Workers\WorkerTaskMonitorService;
use App\Support\WorkerTaskExecutionPlanResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class OrderCancellationControllerTest extends TestCase
{
    public function test_it_accepts_and_dispatches_an_order_cancellation_request(): void
    {
        Str::createUuidsUsing(static fn () => '22222222-2222-2222-2222-222222222222');

        $resolver = Mockery::mock(WorkerTaskExecutionPlanResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturn([
            'request_topic' => 'workerhub.tasks.requests',
        ]);

        $dispatcher = Mockery::mock(WorkerTaskDispatchService::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturn([
            'mode' => 'direct_queue',
            'queue' => 'sales-orders-default',
        ]);

        $monitor = Mockery::mock(WorkerTaskMonitorService::class);
        $monitor->shouldReceive('createTask')->once();
        $monitor->shouldReceive('markQueued')->once()->with('22222222-2222-2222-2222-222222222222', 'sales-orders-default');

        $repository = Mockery::mock(OrderPrototypeRepository::class);
        $repository->shouldReceive('findHeader')->once()->andReturn((object) [
            'f430_id_co' => '002',
            'f430_id_tipo_docto' => 'PFC',
            'f430_consec_docto' => '24116',
        ]);

        $siesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $siesaStateService->shouldReceive('fetch')
            ->once()
            ->andReturn(new \App\Data\Orders\OrderSiesaStateSnapshot('002', 'FC', '24116', true, '002', 'PFC', '24116', 99, 120000.0, 1));

        $legacyState = Mockery::mock(OrderLegacyStateService::class);
        $legacyState->shouldNotReceive('markCancelled');

        $controller = new OrderCancellationController(
            $resolver,
            $dispatcher,
            $monitor,
            $repository,
            $siesaStateService,
            $legacyState
        );

        $request = Request::create('/api/order-cancellations', 'POST', [
            'document_id' => '002-FC-24116',
            'db_connection' => 'sqlsrv',
            'operational_center' => '002',
            'document_type' => 'FC',
            'document_number' => '24116',
            'source' => 'api',
        ]);

        $response = $controller->store($request);

        $this->assertSame(202, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertTrue($payload['accepted']);
        $this->assertSame('22222222-2222-2222-2222-222222222222', $payload['task_id']);

        Str::createUuidsNormally();
    }

    public function test_it_rejects_enqueue_when_the_order_was_already_cancelled(): void
    {
        $resolver = Mockery::mock(WorkerTaskExecutionPlanResolver::class);
        $resolver->shouldNotReceive('resolve');

        $dispatcher = Mockery::mock(WorkerTaskDispatchService::class);
        $dispatcher->shouldNotReceive('dispatch');

        $monitor = Mockery::mock(WorkerTaskMonitorService::class);
        $monitor->shouldNotReceive('createTask');

        $repository = Mockery::mock(OrderPrototypeRepository::class);
        $repository->shouldReceive('findHeader')->once()->andReturn((object) [
            'f430_id_co' => '002',
            'f430_id_tipo_docto' => 'PFC',
            'f430_consec_docto' => '24116',
        ]);

        $siesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $siesaStateService->shouldReceive('fetch')
            ->once()
            ->andReturn(new \App\Data\Orders\OrderSiesaStateSnapshot('002', 'FC', '24116', true, '002', 'PFC', '24116', 99, 120000.0, 9));

        $legacyState = Mockery::mock(OrderLegacyStateService::class);
        $legacyState->shouldReceive('markCancelled')->once();

        $controller = new OrderCancellationController(
            $resolver,
            $dispatcher,
            $monitor,
            $repository,
            $siesaStateService,
            $legacyState
        );

        $request = Request::create('/api/order-cancellations', 'POST', [
            'document_id' => '002-FC-24116',
            'db_connection' => 'sqlsrv',
            'operational_center' => '002',
            'document_type' => 'FC',
            'document_number' => '24116',
            'source' => 'api',
        ]);

        $response = $controller->store($request);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['accepted']);
    }

    public function test_it_rejects_enqueue_when_the_order_was_already_fulfilled(): void
    {
        $resolver = Mockery::mock(WorkerTaskExecutionPlanResolver::class);
        $resolver->shouldNotReceive('resolve');

        $dispatcher = Mockery::mock(WorkerTaskDispatchService::class);
        $dispatcher->shouldNotReceive('dispatch');

        $monitor = Mockery::mock(WorkerTaskMonitorService::class);
        $monitor->shouldNotReceive('createTask');

        $repository = Mockery::mock(OrderPrototypeRepository::class);
        $repository->shouldReceive('findHeader')->once()->andReturn((object) [
            'f430_id_co' => '002',
            'f430_id_tipo_docto' => 'PFC',
            'f430_consec_docto' => '24116',
        ]);

        $siesaStateService = Mockery::mock(OrderSiesaStateService::class);
        $siesaStateService->shouldReceive('fetch')
            ->once()
            ->andReturn(new \App\Data\Orders\OrderSiesaStateSnapshot('002', 'FC', '24116', true, '002', 'PFC', '24116', 99, 120000.0, 4));

        $legacyState = Mockery::mock(OrderLegacyStateService::class);
        $legacyState->shouldNotReceive('markCancelled');

        $controller = new OrderCancellationController(
            $resolver,
            $dispatcher,
            $monitor,
            $repository,
            $siesaStateService,
            $legacyState
        );

        $request = Request::create('/api/order-cancellations', 'POST', [
            'document_id' => '002-FC-24116',
            'db_connection' => 'sqlsrv',
            'operational_center' => '002',
            'document_type' => 'FC',
            'document_number' => '24116',
            'source' => 'api',
        ]);

        $response = $controller->store($request);

        $this->assertSame(409, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertFalse($payload['accepted']);
        $this->assertSame('El pedido ya esta cumplido en Siesa y no se puede anular.', $payload['message']);
    }
}
