<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Workers\Receipts\ReceiptLegacyStateService;
use App\Services\Workers\Receipts\ReceiptPrototypeRepository;
use App\Services\Workers\Receipts\ReceiptSiesaStateService;
use App\Services\Workers\WorkerTaskDispatchService;
use App\Services\Workers\WorkerTaskMonitorService;
use App\Support\WorkerTaskExecutionPlanResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReceiptCancellationController extends Controller
{
    public function __construct(
        private readonly WorkerTaskExecutionPlanResolver $executionPlanResolver,
        private readonly WorkerTaskDispatchService $dispatcher,
        private readonly WorkerTaskMonitorService $monitor,
        private readonly ReceiptPrototypeRepository $repository,
        private readonly ReceiptSiesaStateService $siesaStateService,
        private readonly ReceiptLegacyStateService $legacyStateService
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'receipt_id' => ['nullable', 'integer'],
            'document_id' => ['required', 'string'],
            'db_connection' => ['required', 'string'],
            'operational_center' => ['required', 'string'],
            'document_type' => ['required', 'string'],
            'document_number' => ['required'],
            'company_id' => ['nullable', 'integer'],
            'client_code' => ['nullable', 'string'],
            'client_branch' => ['nullable', 'string'],
            'receipt_total' => ['nullable', 'numeric'],
            'created_by_user_id' => ['nullable', 'integer'],
            'source' => ['nullable', 'string'],
            'priority' => ['nullable', 'in:default,high'],
            'process_key' => ['nullable', 'string'],
            'process_label' => ['nullable', 'string'],
            'schedule_name' => ['nullable', 'string'],
            'task_name' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $metadata = array_merge($validated['metadata'] ?? [], array_filter([
            'process_key' => $validated['process_key'] ?? 'receipts',
            'process_label' => $validated['process_label'] ?? 'Recibos',
            'schedule_name' => $validated['schedule_name'] ?? null,
            'task_name' => $validated['task_name'] ?? null,
            'created_by_user_id' => $validated['created_by_user_id'] ?? null,
        ], static fn ($value) => is_scalar($value) && trim((string) $value) !== ''));

        $payload = [
            'receipt_id' => $validated['receipt_id'] ?? null,
            'document_id' => $validated['document_id'],
            'db_connection' => $validated['db_connection'],
            'operational_center' => trim((string) $validated['operational_center']),
            'document_type' => trim((string) $validated['document_type']),
            'document_number' => trim((string) $validated['document_number']),
            'company_id' => $validated['company_id'] ?? null,
            'client_code' => $validated['client_code'] ?? null,
            'client_branch' => $validated['client_branch'] ?? null,
            'receipt_total' => $validated['receipt_total'] ?? null,
            'created_by_user_id' => $validated['created_by_user_id'] ?? null,
            'source' => $validated['source'] ?? 'api',
            'metadata' => $metadata,
        ];

        $header = $this->repository->findHeader($payload);
        $siesaState = $this->siesaStateService->fetch($payload, $header);

        if ($siesaState->exists && (int) ($siesaState->stateIndicator ?? 0) === 2) {
            $this->legacyStateService->markCancelled($payload, 'Anulado desde Siesa');

            return response()->json([
                'accepted' => false,
                'message' => 'El recibo ya estaba anulado en Siesa.',
                'document_id' => $validated['document_id'],
                'siesa_state' => $siesaState->toArray(),
            ], 409);
        }

        $taskId = (string) Str::uuid();
        $message = [
            'task_id' => $taskId,
            'type' => 'receipt_cancellation',
            'priority' => $validated['priority'] ?? 'default',
            'headers' => [],
            'payload' => $payload,
            'submitted_at' => now()->toIso8601String(),
        ];
        $executionPlan = $this->executionPlanResolver->resolve($message);
        $requestTopic = (string) ($executionPlan['request_topic'] ?? config('workerhub.kafka.topics.requests'));

        $this->monitor->createTask($message, $requestTopic, $validated['document_id']);
        $dispatch = $this->dispatcher->dispatch($requestTopic, $message, $validated['document_id']);

        if ($dispatch['mode'] === 'kafka') {
            $this->monitor->markPublished($taskId);
        } else {
            $this->monitor->markQueued($taskId, (string) $dispatch['queue']);
        }

        return response()->json([
            'accepted' => true,
            'task_id' => $taskId,
            'document_id' => $validated['document_id'],
            'topic' => $requestTopic,
            'dispatch_mode' => $dispatch['mode'],
            'queue' => $dispatch['queue'],
        ], 202);
    }
}
