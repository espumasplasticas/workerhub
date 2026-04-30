<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WorkerTaskProcessingException;
use App\Http\Controllers\Controller;
use App\Services\Workers\Orders\OrderLegacyStateService;
use App\Services\Workers\Orders\OrderPrototypeRepository;
use App\Services\Workers\Orders\OrderSiesaStateService;
use App\Services\Workers\Receipts\ReceiptLegacyStateService;
use App\Services\Workers\Receipts\ReceiptPrototypeRepository;
use App\Services\Workers\Receipts\ReceiptSiesaStateService;
use App\Services\Workers\WorkerTaskDispatchService;
use App\Services\Workers\WorkerTaskDispatchRegistryService;
use App\Services\Workers\WorkerTaskMonitorService;
use App\Support\WorkerTaskExecutionPlanResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkerTaskController extends Controller
{
    public function __construct(
        private readonly WorkerTaskExecutionPlanResolver $executionPlanResolver,
        private readonly WorkerTaskDispatchService $dispatcher,
        private readonly WorkerTaskDispatchRegistryService $dispatchRegistry,
        private readonly WorkerTaskMonitorService $monitor,
        private readonly OrderPrototypeRepository $orderPrototypeRepository,
        private readonly OrderSiesaStateService $orderSiesaStateService,
        private readonly OrderLegacyStateService $orderLegacyStateService,
        private readonly ReceiptPrototypeRepository $receiptPrototypeRepository,
        private readonly ReceiptSiesaStateService $receiptSiesaStateService,
        private readonly ReceiptLegacyStateService $receiptLegacyStateService
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

        if ($type === 'order_migration' && isset($payload['document_id'])) {
            $acceptedDispatch = $this->dispatchRegistry->findAccepted('order', (string) $payload['document_id']);

            if ($acceptedDispatch !== null) {
                return response()->json([
                    'accepted' => true,
                    'duplicate' => true,
                    'task_id' => $acceptedDispatch->task_id,
                    'key' => $key,
                    'accepted_at' => $acceptedDispatch->accepted_at?->toIso8601String(),
                ], 202);
            }
        }

        if ($type === 'receipt_migration' && isset($payload['document_id'])) {
            $acceptedDispatch = $this->dispatchRegistry->findAccepted('receipt', (string) $payload['document_id']);

            if ($acceptedDispatch !== null) {
                return response()->json([
                    'accepted' => true,
                    'duplicate' => true,
                    'task_id' => $acceptedDispatch->task_id,
                    'key' => $key,
                    'accepted_at' => $acceptedDispatch->accepted_at?->toIso8601String(),
                ], 202);
            }
        }

        if ($type === 'order_migration') {
            try {
                $header = $this->orderPrototypeRepository->findHeader($payload);
                $siesaState = $this->orderSiesaStateService->fetch($payload, $header);

                if ($siesaState->exists) {
                    $this->orderLegacyStateService->markDetectedInSiesa($payload, $siesaState);

                    return response()->json([
                        'accepted' => false,
                        'message' => 'El pedido ya existe en Siesa y no debe encolarse nuevamente.',
                        'document_id' => $payload['document_id'] ?? null,
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
        }

        if ($type === 'receipt_migration') {
            try {
                $header = $this->receiptPrototypeRepository->findHeader($payload);
                $siesaState = $this->receiptSiesaStateService->fetch($payload, $header);

                if ($siesaState->exists) {
                    $this->receiptLegacyStateService->markDetectedInSiesa($payload);

                    return response()->json([
                        'accepted' => false,
                        'message' => 'El recibo ya existe en Siesa y no debe encolarse nuevamente.',
                        'document_id' => $payload['document_id'] ?? null,
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
        }

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

        $this->dispatchRegistry->recordAcceptedForTask($type, $payload, $taskId);

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
