<?php

namespace App\Services\Workers;

use App\Data\MonitorTaskFilters;
use App\Events\WorkerTaskUpdated;
use App\Models\WorkerTask;
use App\Models\WorkerTaskEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

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

    public function listTasks(MonitorTaskFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->queryTasks($filters)
            ->with(['parent', 'replays'])
            ->latest('requested_at')
            ->paginate($perPage);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function exportTasks(MonitorTaskFilters $filters, int $limit = 250): array
    {
        return $this->queryTasks($filters)
            ->with(['parent', 'replays'])
            ->latest('requested_at')
            ->limit($limit)
            ->get()
            ->map(fn (WorkerTask $task) => $this->serializeTask($task))
            ->all();
    }

    public function getTask(string $taskId): WorkerTask
    {
        return WorkerTask::query()
            ->with(['events', 'parent', 'replays', 'operationLogs'])
            ->findOrFail($taskId);
    }

    public function listDeadLetters(MonitorTaskFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->listTasks($filters, $perPage);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function exportDeadLetters(MonitorTaskFilters $filters, int $limit = 250): array
    {
        return $this->exportTasks($filters, $limit);
    }

    /**
     * @return array<int, string>
     */
    public function findReplayableTaskIds(MonitorTaskFilters $filters, int $limit = 100): array
    {
        return $this->queryTasks($filters)
            ->whereIn('status', ['failed', 'rejected'])
            ->latest('requested_at')
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function getTaskLineage(string $taskId): array
    {
        $task = WorkerTask::query()->findOrFail($taskId);
        $rootTask = $this->resolveRootTask($task);

        return [
            'requested_task_id' => $taskId,
            'root_task_id' => $rootTask->getKey(),
            'lineage' => $this->serializeLineageNode($rootTask, $taskId),
        ];
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

    private function queryTasks(MonitorTaskFilters $filters): Builder
    {
        return WorkerTask::query()
            ->when($filters->status !== null, fn (Builder $query) => $query->where('status', $filters->status))
            ->when($filters->type !== null, fn (Builder $query) => $query->where('type', $filters->type))
            ->when($filters->source !== null, fn (Builder $query) => $query->where('source', $filters->source))
            ->when($filters->priority !== null, fn (Builder $query) => $query->where('priority', $filters->priority))
            ->when($filters->queue !== null, fn (Builder $query) => $query->where('queue', $filters->queue))
            ->when($filters->dateFrom !== null, fn (Builder $query) => $query->where('requested_at', '>=', $filters->dateFrom))
            ->when($filters->dateTo !== null, fn (Builder $query) => $query->where('requested_at', '<=', $filters->dateTo))
            ->when($filters->onlyDeadLetters, fn (Builder $query) => $query->whereIn('status', ['failed', 'rejected']))
            ->when($filters->replayMode === 'replays', fn (Builder $query) => $query->whereNotNull('parent_task_id'))
            ->when($filters->replayMode === 'originals', fn (Builder $query) => $query->whereNull('parent_task_id'))
            ->when($filters->errorMode === 'with_error', function (Builder $query) {
                $query->whereNotNull('error_message')->where('error_message', '!=', '');
            })
            ->when($filters->errorMode === 'without_error', function (Builder $query) {
                $query->where(function (Builder $builder) {
                    $builder->whereNull('error_message')->orWhere('error_message', '');
                });
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTask(WorkerTask $task): array
    {
        return [
            'id' => $task->id,
            'parent_task_id' => $task->parent_task_id,
            'type' => $task->type,
            'source' => $task->source,
            'status' => $task->status,
            'priority' => $task->priority,
            'queue' => $task->queue,
            'kafka_topic' => $task->kafka_topic,
            'kafka_key' => $task->kafka_key,
            'attempts' => $task->attempts,
            'error_message' => $task->error_message,
            'requested_at' => $task->requested_at?->toIso8601String(),
            'published_at' => $task->published_at?->toIso8601String(),
            'queued_at' => $task->queued_at?->toIso8601String(),
            'processing_at' => $task->processing_at?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'failed_at' => $task->failed_at?->toIso8601String(),
            'replayed_at' => $task->replayed_at?->toIso8601String(),
            'payload' => $task->payload,
            'result' => $task->result,
            'metadata' => $task->metadata,
        ];
    }

    private function resolveRootTask(WorkerTask $task): WorkerTask
    {
        $current = $task;

        while ($current->parent_task_id !== null) {
            $parent = WorkerTask::query()->find($current->parent_task_id);

            if ($parent === null) {
                break;
            }

            $current = $parent;
        }

        return $current;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLineageNode(WorkerTask $task, string $selectedTaskId): array
    {
        $children = WorkerTask::query()
            ->where('parent_task_id', $task->getKey())
            ->latest('requested_at')
            ->get()
            ->map(fn (WorkerTask $child) => $this->serializeLineageNode($child, $selectedTaskId))
            ->all();

        return [
            'id' => $task->id,
            'parent_task_id' => $task->parent_task_id,
            'type' => $task->type,
            'status' => $task->status,
            'priority' => $task->priority,
            'queue' => $task->queue,
            'error_message' => $task->error_message,
            'requested_at' => $task->requested_at?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'failed_at' => $task->failed_at?->toIso8601String(),
            'replayed_at' => $task->replayed_at?->toIso8601String(),
            'is_selected' => $task->getKey() === $selectedTaskId,
            'children' => $children,
        ];
    }
}
