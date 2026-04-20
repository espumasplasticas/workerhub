<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WorkerTaskProcessingException;
use App\Http\Controllers\Controller;
use App\Services\Workers\Orders\OrderLegacyStateService;
use App\Services\Workers\Orders\OrderPrototypeRepository;
use App\Services\Workers\Orders\OrderSiesaStateService;
use App\Services\Workers\WorkerTaskDispatchService;
use App\Services\Workers\WorkerTaskMonitorService;
use App\Support\WorkerTaskExecutionPlanResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrderMigrationController extends Controller
{
    public function __construct(
        private readonly WorkerTaskExecutionPlanResolver $executionPlanResolver,
        private readonly WorkerTaskDispatchService $dispatcher,
        private readonly WorkerTaskMonitorService $monitor,
        private readonly OrderPrototypeRepository $orderPrototypeRepository,
        private readonly OrderSiesaStateService $orderSiesaStateService,
        private readonly OrderLegacyStateService $orderLegacyStateService
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['nullable', 'integer'],
            'document_id' => ['required', 'string'],
            'db_connection' => ['required', 'string'],
            'operational_center' => ['required', 'string'],
            'document_type' => ['required', 'string'],
            'document_number' => ['required'],
            'company_id' => ['nullable', 'integer'],
            'client_code' => ['nullable', 'string'],
            'client_branch' => ['nullable', 'string'],
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
            'process_key' => $validated['process_key'] ?? 'sales_orders',
            'process_label' => $validated['process_label'] ?? 'Pedidos',
            'schedule_name' => $validated['schedule_name'] ?? null,
            'task_name' => $validated['task_name'] ?? null,
        ], static fn ($value) => is_string($value) && trim($value) !== ''));

        $payload = [
            'order_id' => $validated['order_id'] ?? null,
            'document_id' => $validated['document_id'],
            'db_connection' => $validated['db_connection'],
            'operational_center' => trim((string) $validated['operational_center']),
            'document_type' => trim((string) $validated['document_type']),
            'document_number' => trim((string) $validated['document_number']),
            'company_id' => $validated['company_id'] ?? null,
            'client_code' => $validated['client_code'] ?? null,
            'client_branch' => $validated['client_branch'] ?? null,
            'source' => $validated['source'] ?? 'api',
            'metadata' => $metadata,
        ];

        try {
            $header = $this->orderPrototypeRepository->findHeader($payload);
            $siesaState = $this->orderSiesaStateService->fetch($payload, $header);

            if ($siesaState->exists) {
                $this->orderLegacyStateService->markDetectedInSiesa($payload, $siesaState);

                return response()->json([
                    'accepted' => false,
                    'message' => 'El pedido ya existe en Siesa y no debe encolarse nuevamente.',
                    'document_id' => $validated['document_id'],
                    'siesa_state' => $siesaState->toArray(),
                ], 409);
            }
        } catch (WorkerTaskProcessingException $exception) {
            return response()->json([
                'accepted' => false,
                'message' => $exception->getMessage(),
                'context' => $exception->context(),
            ], 422);
        }

        $taskId = (string) Str::uuid();
        $message = [
            'task_id' => $taskId,
            'type' => 'order_migration',
            'priority' => $validated['priority'] ?? 'default',
            'headers' => [],
            'payload' => $payload,
            'submitted_at' => now()->toIso8601String(),
        ];
        $executionPlan = $this->executionPlanResolver->resolve($message);
        $requestTopic = (string) ($executionPlan['request_topic'] ?? config('workerhub.kafka.topics.requests'));

        $this->monitor->createTask(
            $message,
            $requestTopic,
            $validated['document_id']
        );

        $dispatch = $this->dispatcher->dispatch(
            $requestTopic,
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
            'topic' => $requestTopic,
            'dispatch_mode' => $dispatch['mode'],
            'queue' => $dispatch['queue'],
        ], 202);
    }
}
