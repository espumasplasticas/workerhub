<?php

namespace App\Http\Controllers\Api;

use App\Data\MonitorTaskFilters;
use App\Data\OperationLogFilters;
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
        $filters = MonitorTaskFilters::fromArray($request->all());
        $tasks = $this->monitor->listTasks(
            $filters,
            (int) $request->integer('per_page', 25)
        );

        if ($this->shouldRecord($request)) {
            $this->operationLogs->record($request, 'monitor.tasks.index', 'success', null, [
                'filters' => $filters->toArray(),
            ]);
        }

        return response()->json($tasks);
    }

    public function show(Request $request, string $taskId): JsonResponse
    {
        $this->operationLogs->record($request, 'monitor.tasks.show', 'success', $taskId);

        return response()->json($this->monitor->getTask($taskId));
    }

    public function summary(Request $request): JsonResponse
    {
        if ($this->shouldRecord($request)) {
            $this->operationLogs->record($request, 'monitor.tasks.summary', 'success');
        }

        return response()->json($this->monitor->summary());
    }

    public function deadLetters(Request $request): JsonResponse
    {
        $filters = MonitorTaskFilters::fromArray($request->all(), true);

        if ($this->shouldRecord($request)) {
            $this->operationLogs->record($request, 'monitor.dead_letters.index', 'success');
        }

        return response()->json(
            $this->monitor->listDeadLetters($filters, (int) $request->integer('per_page', 25))
        );
    }

    public function exportDeadLetters(Request $request): JsonResponse
    {
        $filters = MonitorTaskFilters::fromArray($request->all(), true);
        $payload = $this->monitor->exportDeadLetters($filters, (int) $request->integer('limit', 250));

        $this->operationLogs->record($request, 'dead_letters.export', 'success', null, [
            'count' => count($payload),
            'filters' => $filters->toArray(),
        ]);

        return response()->json([
            'exported_at' => now()->toIso8601String(),
            'count' => count($payload),
            'filters' => $filters->toArray(),
            'items' => $payload,
        ]);
    }

    public function actions(Request $request): JsonResponse
    {
        $filters = OperationLogFilters::fromArray($request->all());
        if ($this->shouldRecord($request)) {
            $this->operationLogs->record($request, 'monitor.actions.index', 'success');
        }

        return response()->json(
            $this->operationLogs->listRecent($filters, (int) $request->integer('per_page', 25))
        );
    }

    public function exportTasks(Request $request): JsonResponse
    {
        $filters = MonitorTaskFilters::fromArray($request->all());
        $payload = $this->monitor->exportTasks($filters, (int) $request->integer('limit', 250));

        $this->operationLogs->record($request, 'monitor.tasks.export', 'success', null, [
            'count' => count($payload),
            'filters' => $filters->toArray(),
        ]);

        return response()->json([
            'exported_at' => now()->toIso8601String(),
            'count' => count($payload),
            'filters' => $filters->toArray(),
            'items' => $payload,
        ]);
    }

    public function exportActions(Request $request): JsonResponse
    {
        $filters = OperationLogFilters::fromArray($request->all());
        $payload = $this->operationLogs->export($filters, (int) $request->integer('limit', 250));

        $this->operationLogs->record($request, 'monitor.actions.export', 'success', null, [
            'count' => count($payload),
            'filters' => $filters->toArray(),
        ]);

        return response()->json([
            'exported_at' => now()->toIso8601String(),
            'count' => count($payload),
            'filters' => $filters->toArray(),
            'items' => $payload,
        ]);
    }

    public function lineage(Request $request, string $taskId): JsonResponse
    {
        $this->operationLogs->record($request, 'monitor.tasks.lineage', 'success', $taskId);

        return response()->json($this->monitor->getTaskLineage($taskId));
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

    public function retryFiltered(Request $request): JsonResponse
    {
        $filters = MonitorTaskFilters::fromArray($request->all());
        $limit = (int) $request->integer('limit', 100);
        $result = $this->replayService->replayFiltered($filters, $limit);

        $this->operationLogs->record($request, 'task.retry_filtered', 'success', null, [
            'matched_count' => $result['matched_count'],
            'accepted_count' => $result['accepted_count'],
            'error_count' => $result['error_count'],
            'filters' => $filters->toArray(),
        ]);

        return response()->json($result, 202);
    }

    private function shouldRecord(Request $request): bool
    {
        return !filter_var($request->query('silent', false), FILTER_VALIDATE_BOOLEAN);
    }
}
