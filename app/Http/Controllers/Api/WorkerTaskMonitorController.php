<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Workers\WorkerTaskMonitorService;
use App\Services\Workers\WorkerTaskReplayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class WorkerTaskMonitorController extends Controller
{
    public function __construct(
        private readonly WorkerTaskMonitorService $monitor,
        private readonly WorkerTaskReplayService $replayService
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tasks = $this->monitor->listTasks(
            $request->only(['status', 'type', 'source']),
            (int) $request->integer('per_page', 25)
        );

        return response()->json($tasks);
    }

    public function show(string $taskId): JsonResponse
    {
        return response()->json($this->monitor->getTask($taskId));
    }

    public function summary(): JsonResponse
    {
        return response()->json($this->monitor->summary());
    }

    public function deadLetters(Request $request): JsonResponse
    {
        return response()->json(
            $this->monitor->listDeadLetters((int) $request->integer('per_page', 25))
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
            return response()->json(
                $this->replayService->replay($taskId),
                202
            );
        } catch (InvalidArgumentException $exception) {
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

        return response()->json(
            $this->replayService->replayMany($validated['task_ids']),
            202
        );
    }
}
