<?php

namespace App\Jobs;

use App\Services\Integrations\Api\ReceiptMigrationNotificationClient;
use App\Services\Workers\DocumentMigrationService;
use App\Services\Workers\WorkerTaskMonitorService;
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
        DocumentMigrationService $migrationService,
        WorkerTaskMonitorService $monitor,
        ReceiptMigrationNotificationClient $notificationClient
    ): void {
        $taskId = (string) ($this->task['task_id'] ?? '');

        try {
            $monitor->markProcessing($taskId, $this->attempts());

            $result = $migrationService->execute(
                (string) $this->task['type'],
                (array) $this->task['payload'],
                [
                    'task_id' => $taskId,
                    'attempt' => $this->attempts(),
                ]
            );

            $this->task['result'] = $result;
            $monitor->markCompleted($taskId, $result);
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
        if (($this->task['type'] ?? null) !== 'receipt_migration') {
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

    private function resolveCreatedByUserId(): mixed
    {
        return Arr::get($this->task, 'payload.created_by_user_id')
            ?? Arr::get($this->task, 'payload.metadata.created_by_user_id');
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
