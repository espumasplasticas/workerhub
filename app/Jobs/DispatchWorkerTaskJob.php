<?php

namespace App\Jobs;

use App\Services\Integrations\Api\InvoiceMigrationNotificationClient;
use App\Services\Integrations\Api\OrderCancellationNotificationClient;
use App\Services\Integrations\Api\OrderMigrationNotificationClient;
use App\Services\Integrations\Api\ReceiptCancellationNotificationClient;
use App\Services\Integrations\Api\ReceiptMigrationNotificationClient;
use App\Services\Workers\WorkerTaskMonitorService;
use App\Services\Workers\WorkerTaskRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Throwable;

class DispatchWorkerTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private array $task)
    {
        $this->onQueue($this->resolveQueueName());
    }

    public function handle(
        WorkerTaskRouter $taskRouter,
        WorkerTaskMonitorService $monitor,
        InvoiceMigrationNotificationClient $invoiceNotificationClient,
        OrderCancellationNotificationClient $orderCancellationNotificationClient,
        OrderMigrationNotificationClient $orderNotificationClient,
        ReceiptCancellationNotificationClient $receiptCancellationNotificationClient,
        ReceiptMigrationNotificationClient $notificationClient
    ): void {
        $taskId = (string) ($this->task['task_id'] ?? '');

        try {
            $monitor->markProcessing($taskId, $this->attempts());

            $result = $taskRouter->handle($this->task);

            $this->task['result'] = $result;
            $monitor->markCompleted($taskId, $result);
            $this->notifyInvoiceMigrationIfNeeded($invoiceNotificationClient, $monitor, $taskId);
            $this->notifyOrderCancellationIfNeeded($orderCancellationNotificationClient, $monitor, $taskId);
            $this->notifyOrderMigrationIfNeeded($orderNotificationClient, $monitor, $taskId);
            $this->notifyReceiptCancellationIfNeeded($receiptCancellationNotificationClient, $monitor, $taskId);
            $this->notifyReceiptMigrationIfNeeded($notificationClient, $monitor, $taskId);
        } catch (Throwable $exception) {
            $this->reportFailure($monitor, $taskId, $exception);
            throw $exception;
        }
    }

    private function resolveQueueName(): string
    {
        return (string) ($this->task['queue'] ?? config('queue.default', 'default'));
    }

    private function resolveInt(string $key, int $default): int
    {
        $value = $this->task[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    private function notifyReceiptMigrationIfNeeded(
        ReceiptMigrationNotificationClient $notificationClient,
        WorkerTaskMonitorService $monitor,
        string $taskId
    ): void {
        if (!in_array(($this->task['type'] ?? null), ['receipt_migration', 'receipt_cancellation'], true)) {
            return;
        }

        if (Arr::get($this->task, 'payload.source') !== 'api') {
            return;
        }

        $createdByUserId = $this->resolveCreatedByUserId();

        if (!is_numeric($createdByUserId)) {
            $monitor->addEvent(
                $taskId,
                'task.notification.skipped',
                'API notification skipped because created_by_user_id is missing.',
                [
                    'document_id' => Arr::get($this->task, 'payload.document_id'),
                    'created_by_user_id' => $createdByUserId,
                ],
                'warning'
            );
            return;
        }

        try {
            if (($this->task['type'] ?? null) === 'receipt_cancellation') {
                return;
            }

            $notificationClient->notifyReceiptMigrated($this->task);

            if ($taskId !== '') {
                $monitor->addEvent(
                    $taskId,
                    'task.notification.receipt_migrated',
                    'User notified in API after Siesa receipt migration.',
                    [
                        'document_id' => Arr::get($this->task, 'payload.document_id'),
                        'created_by_user_id' => (int) $createdByUserId,
                    ]
                );
            }
        } catch (Throwable $exception) {
            if ($taskId !== '') {
                $monitor->addEvent(
                    $taskId,
                    'task.notification.failed',
                    'API notification after receipt migration failed.',
                    [
                        'document_id' => Arr::get($this->task, 'payload.document_id'),
                        'created_by_user_id' => (int) $createdByUserId,
                        'error' => $exception->getMessage(),
                    ],
                    'warning'
                );
            }
        }
    }

    private function notifyOrderMigrationIfNeeded(
        OrderMigrationNotificationClient $notificationClient,
        WorkerTaskMonitorService $monitor,
        string $taskId
    ): void {
        if (!in_array(($this->task['type'] ?? null), ['order_migration', 'order_cancellation'], true)) {
            return;
        }

        if (Arr::get($this->task, 'payload.source') !== 'api') {
            return;
        }

        $createdByUserId = $this->resolveCreatedByUserId();

        if (!is_numeric($createdByUserId)) {
            $monitor->addEvent(
                $taskId,
                'task.notification.skipped',
                'API notification skipped because created_by_user_id is missing.',
                [
                    'document_id' => Arr::get($this->task, 'payload.document_id'),
                    'created_by_user_id' => $createdByUserId,
                ],
                'warning'
            );
            return;
        }

        try {
            if (($this->task['type'] ?? null) === 'order_cancellation') {
                return;
            }

            $notificationClient->notifyOrderMigrated($this->task);

            if ($taskId !== '') {
                $monitor->addEvent(
                    $taskId,
                    'task.notification.order_migrated',
                    'User notified in API after Siesa order migration.',
                    [
                        'document_id' => Arr::get($this->task, 'payload.document_id'),
                        'created_by_user_id' => (int) $createdByUserId,
                    ]
                );
            }
        } catch (Throwable $exception) {
            if ($taskId !== '') {
                $monitor->addEvent(
                    $taskId,
                    'task.notification.failed',
                    'API notification after order migration failed.',
                    [
                        'document_id' => Arr::get($this->task, 'payload.document_id'),
                        'created_by_user_id' => (int) $createdByUserId,
                        'error' => $exception->getMessage(),
                    ],
                    'warning'
                );
            }
        }
    }

    private function resolveCreatedByUserId(): mixed
    {
        return Arr::get($this->task, 'payload.created_by_user_id')
            ?? Arr::get($this->task, 'payload.metadata.created_by_user_id');
    }

    private function notifyReceiptCancellationIfNeeded(
        ReceiptCancellationNotificationClient $notificationClient,
        WorkerTaskMonitorService $monitor,
        string $taskId
    ): void {
        if (($this->task['type'] ?? null) !== 'receipt_cancellation') {
            return;
        }

        if (Arr::get($this->task, 'payload.source') !== 'api') {
            return;
        }

        $createdByUserId = $this->resolveCreatedByUserId();

        if (!is_numeric($createdByUserId)) {
            return;
        }

        $notificationClient->notifyReceiptCancelled($this->task);
        $monitor->addEvent(
            $taskId,
            'task.notification.receipt_cancelled',
            'User notified in API after Siesa receipt cancellation.',
            [
                'document_id' => Arr::get($this->task, 'payload.document_id'),
                'created_by_user_id' => (int) $createdByUserId,
            ]
        );
    }

    private function notifyOrderCancellationIfNeeded(
        OrderCancellationNotificationClient $notificationClient,
        WorkerTaskMonitorService $monitor,
        string $taskId
    ): void {
        if (($this->task['type'] ?? null) !== 'order_cancellation') {
            return;
        }

        if (Arr::get($this->task, 'payload.source') !== 'api') {
            return;
        }

        $createdByUserId = $this->resolveCreatedByUserId();

        if (!is_numeric($createdByUserId)) {
            return;
        }

        $notificationClient->notifyOrderCancelled($this->task);
        $monitor->addEvent(
            $taskId,
            'task.notification.order_cancelled',
            'User notified in API after Siesa order cancellation.',
            [
                'document_id' => Arr::get($this->task, 'payload.document_id'),
                'created_by_user_id' => (int) $createdByUserId,
            ]
        );
    }

    private function notifyInvoiceMigrationIfNeeded(
        InvoiceMigrationNotificationClient $notificationClient,
        WorkerTaskMonitorService $monitor,
        string $taskId
    ): void {
        if (($this->task['type'] ?? null) !== 'invoice_migration') {
            return;
        }

        if (Arr::get($this->task, 'payload.source') !== 'api') {
            return;
        }

        $createdByUserId = $this->resolveCreatedByUserId();

        if (!is_numeric($createdByUserId)) {
            return;
        }

        $notificationClient->notifyInvoiceMigrated($this->task);
        $monitor->addEvent(
            $taskId,
            'task.notification.invoice_migrated',
            'User notified in API after Siesa invoice migration.',
            [
                'document_id' => Arr::get($this->task, 'payload.document_id'),
                'created_by_user_id' => (int) $createdByUserId,
            ]
        );
    }

    private function reportFailure(
        WorkerTaskMonitorService $monitor,
        string $taskId,
        Throwable $exception
    ): void {
        $monitor->markFailed($taskId, $exception->getMessage(), [
            'exception' => $exception::class,
            'code' => $exception->getCode(),
        ]);
    }
}
