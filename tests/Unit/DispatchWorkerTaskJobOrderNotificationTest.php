<?php

namespace Tests\Unit;

use App\Jobs\DispatchWorkerTaskJob;
use App\Services\Integrations\Api\InvoiceMigrationNotificationClient;
use App\Services\Integrations\Api\OrderCancellationNotificationClient;
use App\Services\Integrations\Api\OrderMigrationNotificationClient;
use App\Services\Integrations\Api\ReceiptCancellationNotificationClient;
use App\Services\Integrations\Api\ReceiptMigrationNotificationClient;
use App\Services\Workers\WorkerTaskDispatchService;
use App\Services\Workers\WorkerTaskMonitorService;
use App\Services\Workers\WorkerTaskRouter;
use Mockery;
use Tests\TestCase;

class DispatchWorkerTaskJobOrderNotificationTest extends TestCase
{
    public function test_it_notifies_api_after_order_migration_when_source_is_api(): void
    {
        $task = [
            'task_id' => 'task-order-notify',
            'type' => 'order_migration',
            'payload' => [
                'document_id' => '002-FC-24116',
                'source' => 'api',
                'created_by_user_id' => 184,
            ],
        ];

        $router = Mockery::mock(WorkerTaskRouter::class);
        $router->shouldReceive('handle')
            ->once()
            ->with($task)
            ->andReturn([
                'document_id' => '002-FC-24116',
                'message' => 'Pedido importado',
            ]);
        $router->shouldNotReceive('resolveExecutionPlan');

        $dispatcher = Mockery::mock(WorkerTaskDispatchService::class);
        $dispatcher->shouldNotReceive('dispatch');

        $monitor = Mockery::mock(WorkerTaskMonitorService::class);
        $monitor->shouldReceive('markProcessing')->once()->with('task-order-notify', 1);
        $monitor->shouldReceive('markCompleted')->once()->with('task-order-notify', Mockery::type('array'));
        $monitor->shouldReceive('addEvent')
            ->once()
            ->with(
                'task-order-notify',
                'task.notification.order_migrated',
                'User notified in API after Siesa order migration.',
                Mockery::on(static fn (array $context): bool => $context['document_id'] === '002-FC-24116' && $context['created_by_user_id'] === 184)
            );

        $orderNotificationClient = Mockery::mock(OrderMigrationNotificationClient::class);
        $orderNotificationClient->shouldReceive('notifyOrderMigrated')->once()->with(Mockery::type('array'));

        $invoiceNotificationClient = Mockery::mock(InvoiceMigrationNotificationClient::class);
        $invoiceNotificationClient->shouldNotReceive('notifyInvoiceMigrated');

        $orderCancellationNotificationClient = Mockery::mock(OrderCancellationNotificationClient::class);
        $orderCancellationNotificationClient->shouldNotReceive('notifyOrderCancelled');

        $receiptCancellationNotificationClient = Mockery::mock(ReceiptCancellationNotificationClient::class);
        $receiptCancellationNotificationClient->shouldNotReceive('notifyReceiptCancelled');

        $receiptNotificationClient = Mockery::mock(ReceiptMigrationNotificationClient::class);
        $receiptNotificationClient->shouldNotReceive('notifyReceiptMigrated');

        $job = new DispatchWorkerTaskJob($task);
        $job->handle(
            $router,
            $dispatcher,
            $monitor,
            $invoiceNotificationClient,
            $orderCancellationNotificationClient,
            $orderNotificationClient,
            $receiptCancellationNotificationClient,
            $receiptNotificationClient
        );
    }
}
