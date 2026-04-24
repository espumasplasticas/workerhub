<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\ReceiptMigrationController;
use App\Services\Workers\Receipts\ReceiptLegacyStateService;
use App\Services\Workers\Receipts\ReceiptPrototypeRepository;
use App\Services\Workers\Receipts\ReceiptSiesaStateService;
use App\Services\Workers\WorkerTaskDispatchService;
use App\Services\Workers\WorkerTaskDispatchRegistryService;
use App\Services\Workers\WorkerTaskMonitorService;
use App\Support\WorkerTaskExecutionPlanResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ReceiptMigrationControllerTest extends TestCase
{
    public function test_it_returns_the_existing_accepted_dispatch_without_reenqueuing(): void
    {
        $resolver = Mockery::mock(WorkerTaskExecutionPlanResolver::class);
        $resolver->shouldNotReceive('resolve');

        $dispatcher = Mockery::mock(WorkerTaskDispatchService::class);
        $dispatcher->shouldNotReceive('dispatch');

        $registryRecord = new \App\Models\WorkerTaskDispatchRegistry([
            'task_id' => 'existing-receipt-task',
            'accepted_at' => now(),
        ]);

        $registry = Mockery::mock(WorkerTaskDispatchRegistryService::class);
        $registry->shouldReceive('findAccepted')->once()->with('receipt', '001-RX-1001')->andReturn($registryRecord);
        $registry->shouldNotReceive('recordAcceptedForTask');

        $monitor = Mockery::mock(WorkerTaskMonitorService::class);
        $monitor->shouldNotReceive('createTask');

        $repository = Mockery::mock(ReceiptPrototypeRepository::class);
        $repository->shouldReceive('hydratePayloadFromReceiptId')->once()->andReturn([
            'receipt_id' => 123,
            'document_id' => '001-RX-1001',
            'db_connection' => 'sqlsrv',
            'operational_center' => '001',
            'document_type' => 'RX',
            'document_number' => '1001',
            'source' => 'api',
        ]);
        $repository->shouldNotReceive('findHeader');

        $siesaStateService = Mockery::mock(ReceiptSiesaStateService::class);
        $siesaStateService->shouldNotReceive('fetch');

        $legacyState = Mockery::mock(ReceiptLegacyStateService::class);
        $legacyState->shouldNotReceive('markDetectedInSiesa');

        $controller = new ReceiptMigrationController(
            $resolver,
            $dispatcher,
            $registry,
            $monitor,
            $repository,
            $siesaStateService,
            $legacyState
        );

        $request = Request::create('/api/receipt-migrations', 'POST', [
            'receipt_id' => 123,
            'document_id' => '001-RX-1001',
            'db_connection' => 'sqlsrv',
            'source' => 'api',
        ]);

        $response = $controller->store($request);

        $this->assertSame(202, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertTrue($payload['accepted']);
        $this->assertTrue($payload['duplicate']);
        $this->assertSame('existing-receipt-task', $payload['task_id']);
    }
}
