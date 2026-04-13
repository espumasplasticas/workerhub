<?php

namespace App\Jobs;

use App\Exceptions\WorkerTaskProcessingException;
use App\Services\Kafka\KafkaMessageProducer;
use App\Services\Workers\WorkerTaskMonitorService;
use App\Services\Workers\WorkerTaskRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        WorkerTaskMonitorService $monitor
    ): void
    {
        $taskId = (string) ($this->task['task_id'] ?? '');
        if ($taskId !== '') {
            $monitor->markProcessing($taskId, $this->attempts());
        }

        $result = $router->handle($this->task);
        $documentId = $this->task['payload']['document_id'] ?? null;

        if ($taskId !== '') {
            $monitor->markCompleted($taskId, $result);
        }

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
    }

    public function failed(Throwable $exception): void
    {
        $taskId = (string) ($this->task['task_id'] ?? '');
        if ($taskId !== '') {
            app(WorkerTaskMonitorService::class)->markFailed(
                $taskId,
                $exception->getMessage(),
                $exception instanceof WorkerTaskProcessingException ? $exception->context() : [],
                $this->attempts()
            );
        }

        app(KafkaMessageProducer::class)->publishFailure([
            'event' => 'worker_task.failed',
            'status' => 'failed',
            'type' => $this->task['type'] ?? 'unknown',
            'task_id' => $this->task['task_id'] ?? null,
            'document_id' => $this->task['payload']['document_id'] ?? null,
            'source' => $this->task['payload']['source'] ?? null,
            'failed_at' => now()->toIso8601String(),
            'error' => $exception->getMessage(),
            'context' => $exception instanceof WorkerTaskProcessingException ? $exception->context() : [],
        ], isset($this->task['payload']['document_id']) ? (string) $this->task['payload']['document_id'] : null);

        app(KafkaMessageProducer::class)->publishDeadLetter([
            'event' => 'worker_task.dead_letter',
            'status' => 'failed',
            'type' => $this->task['type'] ?? 'unknown',
            'task_id' => $this->task['task_id'] ?? null,
            'document_id' => $this->task['payload']['document_id'] ?? null,
            'source' => $this->task['payload']['source'] ?? null,
            'failed_at' => now()->toIso8601String(),
            'error' => $exception->getMessage(),
            'task' => $this->task,
            'context' => $exception instanceof WorkerTaskProcessingException ? $exception->context() : [],
        ], isset($this->task['payload']['document_id']) ? (string) $this->task['payload']['document_id'] : null);
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
}
