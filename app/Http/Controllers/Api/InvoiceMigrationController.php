<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WorkerTaskProcessingException;
use App\Http\Controllers\Controller;
use App\Services\Workers\Invoices\InvoiceLegacyStateService;
use App\Services\Workers\Invoices\InvoicePrototypeRepository;
use App\Services\Workers\Invoices\InvoiceSiesaStateService;
use App\Services\Workers\WorkerTaskDispatchRegistryService;
use App\Services\Workers\WorkerTaskDispatchService;
use App\Services\Workers\WorkerTaskMonitorService;
use App\Support\WorkerTaskExecutionPlanResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InvoiceMigrationController extends Controller
{
    public function __construct(
        private readonly WorkerTaskExecutionPlanResolver $executionPlanResolver,
        private readonly WorkerTaskDispatchService $dispatcher,
        private readonly WorkerTaskDispatchRegistryService $dispatchRegistry,
        private readonly WorkerTaskMonitorService $monitor,
        private readonly InvoicePrototypeRepository $repository,
        private readonly InvoiceSiesaStateService $siesaStateService,
        private readonly InvoiceLegacyStateService $legacyStateService
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['nullable', 'integer'],
            'document_id' => ['nullable', 'string'],
            'db_connection' => ['required', 'string'],
            'operational_center' => ['nullable', 'string'],
            'document_type' => ['nullable', 'string'],
            'document_number' => ['nullable'],
            'company_id' => ['nullable', 'integer'],
            'client_code' => ['nullable', 'string'],
            'client_branch' => ['nullable', 'string'],
            'invoice_total' => ['nullable', 'numeric'],
            'created_by_user_id' => ['nullable', 'integer'],
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
            'process_key' => $validated['process_key'] ?? 'invoices',
            'process_label' => $validated['process_label'] ?? 'Facturas',
            'schedule_name' => $validated['schedule_name'] ?? null,
            'task_name' => $validated['task_name'] ?? null,
            'created_by_user_id' => $validated['created_by_user_id'] ?? null,
        ], static fn ($value) => is_scalar($value) && trim((string) $value) !== ''));

        $payload = [
            'invoice_id' => $validated['invoice_id'] ?? null,
            'document_id' => $validated['document_id'] ?? null,
            'db_connection' => $validated['db_connection'],
            'operational_center' => trim((string) ($validated['operational_center'] ?? '')),
            'document_type' => trim((string) ($validated['document_type'] ?? '')),
            'document_number' => trim((string) ($validated['document_number'] ?? '')),
            'company_id' => $validated['company_id'] ?? null,
            'client_code' => $validated['client_code'] ?? null,
            'client_branch' => $validated['client_branch'] ?? null,
            'invoice_total' => $validated['invoice_total'] ?? null,
            'created_by_user_id' => $validated['created_by_user_id'] ?? null,
            'source' => $validated['source'] ?? 'api',
            'metadata' => $metadata,
        ];

        try {
            $payload = $this->resolveInvoiceMigrationPayload($payload);
        } catch (WorkerTaskProcessingException $exception) {
            return response()->json([
                'accepted' => false,
                'message' => $exception->getMessage(),
                'context' => $exception->context(),
            ], 422);
        }

        $acceptedDispatch = $this->dispatchRegistry->findAccepted('invoice', $payload['document_id']);

        if ($acceptedDispatch !== null) {
            return response()->json([
                'accepted' => true,
                'duplicate' => true,
                'task_id' => $acceptedDispatch->task_id,
                'document_id' => $payload['document_id'],
                'accepted_at' => $acceptedDispatch->accepted_at?->toIso8601String(),
            ], 202);
        }

        try {
            $header = $this->repository->findHeader($payload);
            $siesaState = $this->siesaStateService->fetch($payload, $header);

            if ($siesaState->exists) {
                $this->legacyStateService->markDetectedInSiesa($payload, $siesaState);

                return response()->json([
                    'accepted' => false,
                    'message' => 'La factura ya existe en Siesa y no debe encolarse nuevamente.',
                    'document_id' => $payload['document_id'],
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
            'type' => 'invoice_migration',
            'priority' => $validated['priority'] ?? 'default',
            'headers' => [],
            'payload' => $payload,
            'submitted_at' => now()->toIso8601String(),
        ];
        $executionPlan = $this->executionPlanResolver->resolve($message);
        $requestTopic = (string) ($executionPlan['request_topic'] ?? config('workerhub.kafka.topics.requests'));

        $this->monitor->createTask($message, $requestTopic, $payload['document_id']);
        $dispatch = $this->dispatcher->dispatch($requestTopic, $message, $payload['document_id']);

        if ($dispatch['mode'] === 'kafka') {
            $this->monitor->markPublished($taskId);
        } else {
            $this->monitor->markQueued($taskId, (string) $dispatch['queue']);
        }

        $this->dispatchRegistry->recordAcceptedForTask('invoice_migration', $payload, $taskId);

        return response()->json([
            'accepted' => true,
            'task_id' => $taskId,
            'document_id' => $payload['document_id'],
            'topic' => $requestTopic,
            'dispatch_mode' => $dispatch['mode'],
            'queue' => $dispatch['queue'],
        ], 202);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveInvoiceMigrationPayload(array $payload): array
    {
        if (is_numeric($payload['invoice_id'] ?? null)) {
            return $this->repository->hydratePayloadFromInvoiceId($payload);
        }

        $missing = [];

        foreach (['document_id', 'operational_center', 'document_type', 'document_number'] as $field) {
            if (trim((string) ($payload[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            throw new WorkerTaskProcessingException(
                'El payload de invoice_migration esta incompleto. Configura: ' . implode(', ', $missing) . '.',
                ['missing_fields' => $missing, 'payload' => $payload]
            );
        }

        return $payload;
    }
}
