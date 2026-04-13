<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Workers\WorkerTaskMonitorService;
use App\Services\Workers\WorkerOperationLogService;
use App\Services\Workers\WorkerTaskReplayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class WorkerTaskMonitorController extends Controller
{
    public function __construct(
        private readonly WorkerTaskMonitorService $monitor,
        private readonly WorkerTaskReplayService $replayService,
        private readonly WorkerOperationLogService $operationLogs
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tasks = $this->monitor->listTasks(
            $request->only(['status', 'type', 'source']),
            (int) $request->integer('per_page', 25)
        );

        $this->operationLogs->record($request, 'monitor.tasks.index', 'success', null, [
            'filters' => $request->only(['status', 'type', 'source']),
        ]);

        return response()->json($tasks);
    }

    public function show(Request $request, string $taskId): JsonResponse
    {
        $this->operationLogs->record($request, 'monitor.tasks.show', 'success', $taskId);

        return response()->json($this->monitor->getTask($taskId));
    }

    public function summary(Request $request): JsonResponse
    {
        $this->operationLogs->record($request, 'monitor.tasks.summary', 'success');

        return response()->json($this->monitor->summary());
    }

    public function deadLetters(Request $request): JsonResponse
    {
        $this->operationLogs->record($request, 'monitor.dead_letters.index', 'success');

        return response()->json(
            $this->monitor->listDeadLetters((int) $request->integer('per_page', 25))
        );
    }

    public function exportDeadLetters(Request $request): JsonResponse
    {
        $tasks = $this->monitor->listDeadLetters((int) $request->integer('per_page', 100))->items();

        $payload = array_map(static function ($task) {
            return [
                'id' => $task->id,
                'type' => $task->type,
                'source' => $task->source,
                'status' => $task->status,
                'priority' => $task->priority,
                'queue' => $task->queue,
                'attempts' => $task->attempts,
                'error_message' => $task->error_message,
                'payload' => $task->payload,
                'metadata' => $task->metadata,
            ];
        }, $tasks);

        $this->operationLogs->record($request, 'dead_letters.export', 'success', null, [
            'count' => count($payload),
        ]);

        return response()->json([
            'exported_at' => now()->toIso8601String(),
            'count' => count($payload),
            'items' => $payload,
        ]);
    }

    public function actions(Request $request): JsonResponse
    {
        $this->operationLogs->record($request, 'monitor.actions.index', 'success');

        return response()->json(
            $this->operationLogs->listRecent((int) $request->integer('per_page', 25))
        );
    }

    public function socketConfig(): JsonResponse
    {
        return response()->json([
            'broadcaster' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'host' => env('VITE_PUSHER_HOST', env('PUSHER_HOST')),
            'port' => (int) env('VITE_PUSHER_PORT', env('PUSHER_PORT', 6001)),
            'scheme' => env('VITE_PUSHER_SCHEME', env('PUSHER_SCHEME', 'http')),
            'cluster' => env('VITE_PUSHER_APP_CLUSTER', env('PUSHER_APP_CLUSTER', 'mt1')),
            'channels' => [
                'monitor' => (string) config('workerhub.broadcasting.channel'),
                'task_prefix' => (string) config('workerhub.broadcasting.task_channel_prefix'),
            ],
        ]);
    }

    public function retry(string $taskId): JsonResponse
    {
        try {
            $result = $this->replayService->replay($taskId);
            $this->operationLogs->record(request(), 'task.retry', 'success', $taskId, [
                'new_task_id' => $result['task_id'] ?? null,
            ]);

            return response()->json($result, 202);
        } catch (InvalidArgumentException $exception) {
            $this->operationLogs->record(request(), 'task.retry', 'failed', $taskId, [
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function retryBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['required', 'string'],
        ]);

        $result = $this->replayService->replayMany($validated['task_ids']);
        $this->operationLogs->record($request, 'task.retry_batch', 'success', null, [
            'task_ids' => $validated['task_ids'],
            'accepted_count' => $result['accepted_count'],
            'error_count' => $result['error_count'],
        ]);

        return response()->json($result, 202);
    }
}
