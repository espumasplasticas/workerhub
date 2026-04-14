<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Workers\WorkerTaskDispatchService;
use App\Services\Workers\WorkerTaskMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DocumentMigrationController extends Controller
{
    public function __construct(
        private readonly WorkerTaskDispatchService $dispatcher,
        private readonly WorkerTaskMonitorService $monitor
    )
    {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_id' => ['required', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['required'],
            'source' => ['nullable', 'string'],
            'priority' => ['nullable', 'in:default,high'],
            'process_key' => ['nullable', 'string'],
            'process_label' => ['nullable', 'string'],
            'schedule_name' => ['nullable', 'string'],
            'task_name' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $metadata = $validated['metadata'] ?? [];
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata = array_merge($metadata, array_filter([
            'process_key' => $validated['process_key'] ?? null,
            'process_label' => $validated['process_label'] ?? null,
            'schedule_name' => $validated['schedule_name'] ?? null,
            'task_name' => $validated['task_name'] ?? null,
        ], static fn ($value) => is_string($value) && trim($value) !== ''));

        $taskId = (string) Str::uuid();
        $message = [
            'task_id' => $taskId,
            'type' => 'document_migration',
            'priority' => $validated['priority'] ?? 'default',
            'headers' => [],
            'payload' => [
                'document_id' => $validated['document_id'],
                'lines' => array_map(static fn ($line) => (string) $line, $validated['lines']),
                'source' => $validated['source'] ?? 'api',
                'metadata' => $metadata,
            ],
            'submitted_at' => now()->toIso8601String(),
        ];

        $this->monitor->createTask(
            $message,
            (string) config('workerhub.kafka.topics.requests'),
            $validated['document_id']
        );

        $dispatch = $this->dispatcher->dispatch(
            (string) config('workerhub.kafka.topics.requests'),
            $message,
            $validated['document_id']
        );

        if ($dispatch['mode'] === 'kafka') {
            $this->monitor->markPublished($taskId);
        } else {
            $this->monitor->markQueued($taskId, (string) $dispatch['queue']);
        }

        return response()->json([
            'accepted' => true,
            'task_id' => $taskId,
            'document_id' => $validated['document_id'],
            'topic' => config('workerhub.kafka.topics.requests'),
            'dispatch_mode' => $dispatch['mode'],
            'queue' => $dispatch['queue'],
        ], 202);
    }
}
