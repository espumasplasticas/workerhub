<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WorkerTaskExecutionPlanResolver;
use App\Services\Workers\WorkerTaskDispatchService;
use App\Services\Workers\WorkerTaskMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkerTaskController extends Controller
{
    public function __construct(
        private readonly WorkerTaskExecutionPlanResolver $executionPlanResolver,
        private readonly WorkerTaskDispatchService $dispatcher,
        private readonly WorkerTaskMonitorService $monitor
    )
    {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string'],
            'payload' => ['required', 'array'],
            'priority' => ['nullable', 'string'],
            'headers' => ['nullable', 'array'],
            'task_id' => ['nullable', 'string'],
        ]);

        $taskId = $validated['task_id'] ?? (string) Str::uuid();
        $type = $validated['type'];
        $payload = $validated['payload'];
        $key = isset($payload['document_id']) ? (string) $payload['document_id'] : $taskId;

        $message = [
            'task_id' => $taskId,
            'type' => $type,
            'priority' => $validated['priority'] ?? 'default',
            'headers' => $validated['headers'] ?? [],
            'payload' => $payload,
            'submitted_at' => now()->toIso8601String(),
        ];
        $executionPlan = $this->executionPlanResolver->resolve($message);
        $requestTopic = (string) ($executionPlan['request_topic'] ?? config('workerhub.kafka.topics.requests'));

        $this->monitor->createTask(
            $message,
            $requestTopic,
            $key
        );

        $dispatch = $this->dispatcher->dispatch(
            $requestTopic,
            $message,
            $key,
            $validated['headers'] ?? []
        );

        if ($dispatch['mode'] === 'kafka') {
            $this->monitor->markPublished($taskId);
        } else {
            $this->monitor->markQueued($taskId, (string) $dispatch['queue']);
        }

        return response()->json([
            'accepted' => true,
            'task_id' => $taskId,
            'topic' => $requestTopic,
            'dispatch_mode' => $dispatch['mode'],
            'queue' => $dispatch['queue'],
            'key' => $key,
        ], 202);
    }
}
