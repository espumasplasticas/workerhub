<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Kafka\KafkaMessageProducer;
use App\Services\Workers\WorkerTaskMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkerTaskController extends Controller
{
    public function __construct(
        private readonly KafkaMessageProducer $producer,
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

        $this->monitor->createTask(
            $message,
            (string) config('workerhub.kafka.topics.requests'),
            $key
        );

        $this->producer->publish(
            (string) config('workerhub.kafka.topics.requests'),
            $message,
            $key,
            $validated['headers'] ?? []
        );

        $this->monitor->markPublished($taskId);

        return response()->json([
            'accepted' => true,
            'task_id' => $taskId,
            'topic' => config('workerhub.kafka.topics.requests'),
            'key' => $key,
        ], 202);
    }
}
