<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\InvoiceMigrationController;
use App\Services\Workers\Invoices\InvoiceLegacyStateService;
use App\Services\Workers\Invoices\InvoicePrototypeRepository;
use App\Services\Workers\Invoices\InvoiceSiesaStateService;
use App\Support\WorkerTaskExecutionPlanResolver;
use App\Services\Workers\WorkerTaskDispatchService;
use App\Services\Workers\WorkerTaskMonitorService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class InvoiceMigrationControllerTest extends TestCase
{
    public function test_it_accepts_and_dispatches_an_invoice_migration_without_touching_the_database(): void
    {
        Str::createUuidsUsing(static fn () => '22222222-2222-2222-2222-222222222222');

        $resolver = Mockery::mock(WorkerTaskExecutionPlanResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturn([
            'request_topic' => 'workerhub.tasks.requests',
            'process_key' => 'invoices',
            'process_label' => 'Facturas',
        ]);

        $dispatcher = Mockery::mock(WorkerTaskDispatchService::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturn([
            'mode' => 'direct_queue',
            'queue' => 'invoices-default',
        ]);

        $monitor = Mockery::mock(WorkerTaskMonitorService::class);
        $monitor->shouldReceive('createTask')->once();
        $monitor->shouldReceive('markQueued')->once()->with('22222222-2222-2222-2222-222222222222', 'invoices-default');

        $repository = Mockery::mock(InvoicePrototypeRepository::class);
        $repository->shouldReceive('findHeader')->once()->andReturn((object) [
            'F350_ID_CO' => '002',
            'F350_ID_TIPO_DOCTO' => 'TFE',
            'F350_CONSEC_DOCTO' => '5001',
        ]);

        $siesa = Mockery::mock(InvoiceSiesaStateService::class);
        $siesa->shouldReceive('fetch')->once()->andReturn(new \App\Data\Invoices\InvoiceSiesaStateSnapshot('002', 'TFE', '5001', false));

        $legacy = Mockery::mock(InvoiceLegacyStateService::class);
        $legacy->shouldNotReceive('markDetectedInSiesa');

        $controller = new InvoiceMigrationController($resolver, $dispatcher, $monitor, $repository, $siesa, $legacy);
        $request = Request::create('/api/invoice-migrations', 'POST', [
            'invoice_id' => 99,
            'document_id' => '002-TFE-5001',
            'db_connection' => 'sqlsrv',
            'operational_center' => '002',
            'document_type' => 'TFE',
            'document_number' => '5001',
            'source' => 'api',
        ]);

        $response = $controller->store($request);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['accepted']);
        Str::createUuidsNormally();
    }
}
