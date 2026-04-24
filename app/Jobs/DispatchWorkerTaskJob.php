<?php

namespace App\Jobs;

use App\Services\Integrations\Api\InvoiceMigrationNotificationClient;
use App\Services\Integrations\Api\OrderCancellationNotificationClient;
use App\Services\Integrations\Api\OrderMigrationNotificationClient;
use App\Services\Integrations\Api\ReceiptCancellationNotificationClient;
use App\Services\Integrations\Api\ReceiptMigrationNotificationClient;
use App\Models\WorkerTask;
use App\Services\Workers\WorkerTaskDispatchService;
use App\Services\Workers\WorkerTaskMonitorService;
use App\Services\Workers\WorkerTaskRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
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
        WorkerTaskDispatchService $dispatcher,
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
            $this->enqueueOrderDeliveryGenerationIfNeeded($taskRouter, $dispatcher, $monitor, $taskId);
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

    /**
     * Define un backoff explicito para los follow-ups que dependen de cambios de estado
     * posteriores en Siesa, como la generacion de domicilios.
     *
     * @return list<int>|int
     */
    public function backoff(): array|int
    {
        if (($this->task['type'] ?? null) === 'order_delivery_generation') {
            return [60, 180, 300, 600, 900];
        }

        return 0;
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

    private function enqueueOrderDeliveryGenerationIfNeeded(
        WorkerTaskRouter $taskRouter,
        WorkerTaskDispatchService $dispatcher,
        WorkerTaskMonitorService $monitor,
        string $taskId
    ): void {
        if (($this->task['type'] ?? null) !== 'order_migration') {
            return;
        }

        if (!Arr::get($this->task, 'result.siesa_state.exists', false)) {
            return;
        }

        if (WorkerTask::query()
            ->where('parent_task_id', $taskId)
            ->where('type', 'order_delivery_generation')
            ->exists()) {
            return;
        }

        $payload = Arr::get($this->task, 'payload', []);

        if (!is_array($payload)) {
            return;
        }

        $documentId = trim((string) ($payload['document_id'] ?? ''));

        if ($documentId === '') {
            return;
        }

        $metadata = Arr::get($payload, 'metadata', []);
        $metadata = is_array($metadata) ? $metadata : [];
        $followUpPayload = array_merge($payload, [
            'metadata' => array_merge($metadata, [
                'process_key' => 'deliveries',
                'process_label' => 'Domicilios',
                'task_name' => 'Generacion domicilio pedido',
                'generated_from_task_id' => $taskId,
                'generated_from_task_type' => 'order_migration',
            ]),
        ]);

        $followUpTaskId = (string) Str::uuid();
        $followUpTask = [
            'task_id' => $followUpTaskId,
            'parent_task_id' => $taskId,
            'type' => 'order_delivery_generation',
            'priority' => $this->task['priority'] ?? 'default',
            'headers' => [],
            'payload' => $followUpPayload,
            'submitted_at' => now()->toIso8601String(),
        ];

        try {
            $executionPlan = $taskRouter->resolveExecutionPlan($followUpTask);
            $requestTopic = (string) ($executionPlan['request_topic'] ?? config('workerhub.kafka.topics.requests'));

            $monitor->createTask($followUpTask, $requestTopic, $documentId);

            $dispatch = $dispatcher->dispatch(
                $requestTopic,
                $followUpTask,
                $documentId,
                [
                    'workerhub-parent-task-id' => $taskId,
                    'workerhub-origin-task-type' => 'order_migration',
                ]
            );

            if ($dispatch['mode'] === 'kafka') {
                $monitor->markPublished($followUpTaskId);
            } else {
                $monitor->markQueued($followUpTaskId, (string) $dispatch['queue']);
            }

            $monitor->addEvent(
                $taskId,
                'task.follow_up.order_delivery_generation',
                'Order delivery generation task enqueued after successful order migration.',
                [
                    'child_task_id' => $followUpTaskId,
                    'document_id' => $documentId,
                    'dispatch_mode' => $dispatch['mode'],
                    'queue' => $dispatch['queue'],
                    'topic' => $requestTopic,
                ]
            );
        } catch (Throwable $exception) {
            $monitor->addEvent(
                $taskId,
                'task.follow_up.order_delivery_generation_failed',
                'Order delivery generation follow-up could not be enqueued.',
                [
                    'document_id' => $documentId,
                    'error' => $exception->getMessage(),
                ],
                'warning'
            );
        }
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
