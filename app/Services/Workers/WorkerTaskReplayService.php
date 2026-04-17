<?php

namespace App\Services\Workers;

use App\Data\MonitorTaskFilters;
use App\Models\WorkerTask;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WorkerTaskReplayService
{
    public function __construct(
        private readonly WorkerTaskMonitorService $monitor,
        private readonly WorkerTaskDispatchService $dispatcher,
        private readonly WorkerTaskReplayEligibilityService $eligibility
    ) {
    }

    /**
     * Reencola manualmente una tarea terminal y crea un nuevo registro hijo.
     *
     * @return array<string, mixed>
     */
    public function replay(string $taskId): array
    {
        $task = WorkerTask::query()->findOrFail($taskId);

        $eligibility = $this->eligibility->inspect($task);

        if (!$eligibility['can_retry']) {
            throw new InvalidArgumentException((string) ($eligibility['reason'] ?? 'La tarea no se puede reencolar.'));
        }

        $headers = [];

        if (is_array($task->metadata) && isset($task->metadata['headers']) && is_array($task->metadata['headers'])) {
            $headers = $task->metadata['headers'];
        }

        $newTaskId = (string) Str::uuid();
        $payload = is_array($task->payload) ? $task->payload : [];
        $key = isset($payload['document_id']) ? (string) $payload['document_id'] : $newTaskId;

        $message = [
            'task_id' => $newTaskId,
            'parent_task_id' => $task->getKey(),
            'type' => $task->type,
            'priority' => $task->priority,
            'headers' => $headers,
            'payload' => $payload,
            'submitted_at' => now()->toIso8601String(),
        ];

        $topic = (string) config('workerhub.kafka.topics.requests');

        $this->monitor->createTask($message, $topic, $key);
        $dispatch = $this->dispatcher->dispatch($topic, $message, $key, $headers);

        if ($dispatch['mode'] === 'kafka') {
            $this->monitor->markPublished($newTaskId);
        } else {
            $this->monitor->markQueued($newTaskId, (string) $dispatch['queue']);
        }

        $this->monitor->markReplayed($taskId, $newTaskId);

        return [
            'accepted' => true,
            'task_id' => $newTaskId,
            'replayed_from_task_id' => $taskId,
            'topic' => $topic,
            'dispatch_mode' => $dispatch['mode'],
            'queue' => $dispatch['queue'],
            'key' => $key,
        ];
    }

    /**
     * @param array<int, string> $taskIds
     * @return array<string, mixed>
     */
    public function replayMany(array $taskIds): array
    {
        $accepted = [];
        $errors = [];

        foreach ($taskIds as $taskId) {
            try {
                $accepted[] = $this->replay((string) $taskId);
            } catch (\Throwable $exception) {
                $errors[] = [
                    'task_id' => (string) $taskId,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return [
            'accepted_count' => count($accepted),
            'error_count' => count($errors),
            'accepted' => $accepted,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function replayFiltered(MonitorTaskFilters $filters, int $limit = 100): array
    {
        $taskIds = $this->monitor->findReplayableTaskIds($filters, $limit);
        $result = $this->replayMany($taskIds);

        $result['matched_count'] = count($taskIds);
        $result['applied_filters'] = $filters->toArray();

        return $result;
    }
}
