<?php

namespace App\Services\Workers;

use App\Events\WorkerTaskUpdated;
use App\Models\WorkerTask;
use App\Models\WorkerTaskEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\Builder;

class WorkerTaskMonitorService
{
    public function __construct(private readonly WorkerTaskNotificationService $notifications)
    {
    }

    public function createTask(array $task, string $topic, ?string $key = null): WorkerTask
    {
        $payload = $task['payload'] ?? [];

        $record = WorkerTask::query()->create([
            'id' => $task['task_id'],
            'parent_task_id' => $task['parent_task_id'] ?? null,
            'type' => $task['type'],
            'source' => Arr::get($payload, 'source'),
            'status' => 'received',
            'priority' => $task['priority'] ?? 'default',
            'kafka_topic' => $topic,
            'kafka_key' => $key,
            'payload' => $payload,
            'metadata' => [
                'headers' => $task['headers'] ?? [],
                'submitted_at' => $task['submitted_at'] ?? null,
                'replayed_from_task_id' => $task['parent_task_id'] ?? null,
            ],
            'requested_at' => now(),
        ]);

        $this->addEvent($record->id, 'task.received', 'Task received by WorkerHub.');
        $this->broadcastUpdate($record, 'task.received', 'Task received by WorkerHub.');

        return $record;
    }

    public function markPublished(string $taskId): void
    {
        $this->updateTask($taskId, [
            'status' => 'published',
            'published_at' => now(),
        ], 'task.published', 'Task published to Kafka.');
    }

    public function markQueued(string $taskId, string $queue): void
    {
        $this->updateTask($taskId, [
            'status' => 'queued',
            'queue' => $queue,
            'queued_at' => now(),
        ], 'task.queued', 'Task enqueued in Redis/Horizon.', ['queue' => $queue]);
    }

    public function markRejected(string $taskId, string $message, array $context = []): void
    {
        $this->updateTask($taskId, [
            'status' => 'rejected',
            'failed_at' => now(),
            'error_message' => $message,
        ], 'task.rejected', $message, $context, 'warning');
    }

    public function markProcessing(string $taskId, int $attempts): void
    {
        $this->updateTask($taskId, [
            'status' => 'processing',
            'attempts' => $attempts,
            'processing_at' => now(),
        ], 'task.processing', 'Task started processing.', ['attempts' => $attempts]);
    }

    public function markCompleted(string $taskId, array $result = []): void
    {
        $this->updateTask($taskId, [
            'status' => 'completed',
            'result' => $result,
            'completed_at' => now(),
            'error_message' => null,
        ], 'task.completed', 'Task completed successfully.', $result);

        $task = WorkerTask::query()->find($taskId);
        if ($task !== null) {
            $this->notifications->notifyCompleted($task);
        }
    }

    public function markFailed(string $taskId, string $message, array $context = [], int $attempts = 0): void
    {
        $this->updateTask($taskId, [
            'status' => 'failed',
            'attempts' => $attempts,
            'failed_at' => now(),
            'error_message' => $message,
        ], 'task.failed', $message, $context, 'error');

        $task = WorkerTask::query()->find($taskId);
        if ($task !== null) {
            $this->notifications->notifyFailed($task);
        }
    }

    public function listTasks(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return WorkerTask::query()
            ->with(['parent', 'replays'])
            ->when(isset($filters['status']) && $filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(isset($filters['type']) && $filters['type'] !== '', fn (Builder $query) => $query->where('type', $filters['type']))
            ->when(isset($filters['source']) && $filters['source'] !== '', fn (Builder $query) => $query->where('source', $filters['source']))
            ->when(($filters['only_dead_letters'] ?? false), fn (Builder $query) => $query->whereIn('status', ['failed', 'rejected']))
            ->latest('requested_at')
            ->paginate($perPage);
    }

    public function getTask(string $taskId): WorkerTask
    {
        return WorkerTask::query()
            ->with(['events', 'parent', 'replays'])
            ->findOrFail($taskId);
    }

    public function listDeadLetters(int $perPage = 25): LengthAwarePaginator
    {
        return $this->listTasks(['only_dead_letters' => true], $perPage);
    }

    public function summary(): array
    {
        $base = WorkerTask::query();

        return [
            'total' => (clone $base)->count(),
            'received' => (clone $base)->where('status', 'received')->count(),
            'published' => (clone $base)->where('status', 'published')->count(),
            'queued' => (clone $base)->where('status', 'queued')->count(),
            'processing' => (clone $base)->where('status', 'processing')->count(),
            'completed' => (clone $base)->where('status', 'completed')->count(),
            'failed' => (clone $base)->whereIn('status', ['failed', 'rejected'])->count(),
            'dead_letters' => (clone $base)->whereIn('status', ['failed', 'rejected'])->count(),
            'replayed' => (clone $base)->whereNotNull('parent_task_id')->count(),
        ];
    }

    public function addEvent(
        string $taskId,
        string $event,
        ?string $message = null,
        array $context = [],
        string $level = 'info'
    ): void {
        WorkerTaskEvent::query()->create([
            'worker_task_id' => $taskId,
            'event' => $event,
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ]);
    }

    private function updateTask(
        string $taskId,
        array $attributes,
        string $event,
        ?string $message = null,
        array $context = [],
        string $level = 'info'
    ): void {
        WorkerTask::query()->whereKey($taskId)->update($attributes);
        $this->addEvent($taskId, $event, $message, $context, $level);

        $task = WorkerTask::query()->find($taskId);

        if ($task !== null) {
            $this->broadcastUpdate($task, $event, $message, $context, $level);
        }
    }

    public function markReplayed(string $taskId, string $newTaskId): void
    {
        $task = WorkerTask::query()->findOrFail($taskId);
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $replays = $metadata['replays'] ?? [];

        if (!is_array($replays)) {
            $replays = [];
        }

        $replays[] = [
            'task_id' => $newTaskId,
            'replayed_at' => now()->toIso8601String(),
        ];

        $metadata['replays'] = $replays;

        $task->forceFill([
            'metadata' => $metadata,
            'replayed_at' => now(),
        ])->save();

        $this->addEvent(
            $task->getKey(),
            'task.replayed',
            'Task replayed manually.',
            ['new_task_id' => $newTaskId]
        );

        $this->broadcastUpdate(
            $task->fresh(),
            'task.replayed',
            'Task replayed manually.',
            ['new_task_id' => $newTaskId]
        );
    }

    private function broadcastUpdate(
        WorkerTask $task,
        string $event,
        ?string $message = null,
        array $context = [],
        string $level = 'info'
    ): void {
        event(new WorkerTaskUpdated($task, $event, $message, $context, $level));
    }
}
