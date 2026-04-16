<?php

namespace App\Jobs;

use App\Exceptions\WorkerTaskProcessingException;
use App\Models\WorkerTask;
use App\Services\Integrations\Api\ReceiptMigrationNotificationClient;
use App\Services\Kafka\KafkaMessageProducer;
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
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;
    public int $timeout;

    public function __construct(public array $task)
    {
        $this->tries = $this->resolveInt('tries', 3);
        $this->timeout = $this->resolveInt('timeout', 300);
    }

    public function handle(
        WorkerTaskRouter $router,
        KafkaMessageProducer $producer,
        WorkerTaskMonitorService $monitor,
        ReceiptMigrationNotificationClient $notificationClient
    ): void
    {
        $taskId = (string) ($this->task['task_id'] ?? '');

        try {
            if ($taskId !== '') {
                $monitor->markProcessing($taskId, $this->attempts());
            }

            $result = $router->handle($this->task);
            $this->task['result'] = $result;
            $documentId = $this->task['payload']['document_id'] ?? null;

            if ($taskId !== '') {
                $monitor->markCompleted($taskId, $result);
            }

            $this->notifyReceiptMigrationIfNeeded($notificationClient, $monitor, $taskId);

            $producer->publishResult([
                'event' => 'worker_task.completed',
                'status' => 'completed',
                'type' => $this->task['type'] ?? 'unknown',
                'task_id' => $this->task['task_id'] ?? null,
                'document_id' => $documentId,
                'source' => $this->task['payload']['source'] ?? null,
                'processed_at' => now()->toIso8601String(),
                'result' => $result,
            ], is_scalar($documentId) ? (string) $documentId : null);
        } catch (Throwable $exception) {
            $this->reportFailure($exception, $monitor, $producer);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $taskId = (string) ($this->task['task_id'] ?? '');

        if ($taskId !== '' && WorkerTask::query()->whereKey($taskId)->whereIn('status', ['failed', 'rejected'])->exists()) {
            return;
        }

        $this->reportFailure(
            $exception,
            app(WorkerTaskMonitorService::class),
            app(KafkaMessageProducer::class)
        );
    }

    public function tags(): array
    {
        return array_filter([
            'workerhub',
            'type:' . ($this->task['type'] ?? 'unknown'),
            'task:' . ($this->task['task_id'] ?? 'unknown'),
            isset($this->task['payload']['document_id']) ? 'document:' . $this->task['payload']['document_id'] : null,
        ]);
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

        if (!is_numeric(Arr::get($this->task, 'payload.created_by_user_id'))) {
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
                        'created_by_user_id' => Arr::get($this->task, 'payload.created_by_user_id'),
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
                        'created_by_user_id' => Arr::get($this->task, 'payload.created_by_user_id'),
                        'error' => $exception->getMessage(),
                    ],
                    'warning'
                );
            }
        }
    }

    private function reportFailure(
        Throwable $exception,
        WorkerTaskMonitorService $monitor,
        KafkaMessageProducer $producer
    ): void {
        $taskId = (string) ($this->task['task_id'] ?? '');
        $documentId = $this->task['payload']['document_id'] ?? null;
        $context = $exception instanceof WorkerTaskProcessingException ? $exception->context() : [];

        if ($taskId !== '') {
            $monitor->markFailed(
                $taskId,
                $exception->getMessage(),
                $context,
                $this->attempts()
            );
        }

        $producer->publishFailure([
            'event' => 'worker_task.failed',
            'status' => 'failed',
            'type' => $this->task['type'] ?? 'unknown',
            'task_id' => $this->task['task_id'] ?? null,
            'document_id' => $documentId,
            'source' => $this->task['payload']['source'] ?? null,
            'failed_at' => now()->toIso8601String(),
            'error' => $exception->getMessage(),
            'context' => $context,
        ], is_scalar($documentId) ? (string) $documentId : null);

        $producer->publishDeadLetter([
            'event' => 'worker_task.dead_letter',
            'status' => 'failed',
            'type' => $this->task['type'] ?? 'unknown',
            'task_id' => $this->task['task_id'] ?? null,
            'document_id' => $documentId,
            'source' => $this->task['payload']['source'] ?? null,
            'failed_at' => now()->toIso8601String(),
            'error' => $exception->getMessage(),
            'task' => $this->task,
            'context' => $context,
        ], is_scalar($documentId) ? (string) $documentId : null);
    }
}