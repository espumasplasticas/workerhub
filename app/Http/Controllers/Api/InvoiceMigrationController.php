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
        // Minimal validation: require `invoice_id` + `db_connection` only.
        // Other reference fields (document_id, operational_center, ...) are removed
        // to keep the request minimal — WorkerHub will hydrate them server-side
        // using `invoice_id`.
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer'],
            'db_connection' => ['required', 'string'],
            'company_id' => ['nullable', 'integer'],
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

        // Build a minimal payload — avoid sending redundant reference fields.
        $payload = [
            'invoice_id' => $validated['invoice_id'],
            'db_connection' => $validated['db_connection'],
            'company_id' => $validated['company_id'] ?? null,
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

        throw new WorkerTaskProcessingException(
            'El payload de invoice_migration requiere `invoice_id` para resolverse. No se aceptan referencias completas desde el cliente; envía solo `invoice_id` y `db_connection`.',
            ['payload' => $payload]
        );
    }
}
