<?php

namespace App\Services\Workers;

use App\Exceptions\WorkerTaskProcessingException;
use App\Support\WorkerTaskExecutionPlanResolver;
use InvalidArgumentException;

class WorkerTaskRouter
{
    public function __construct(
        private readonly WorkerTaskExecutionPlanResolver $executionPlan,
        private readonly DocumentMigrationService $documentMigrationService,
        private readonly InvoiceMigrationService $invoiceMigrationService,
        private readonly OrderCancellationService $orderCancellationService,
        private readonly OrderMigrationService $orderMigrationService,
        private readonly ReceiptCancellationService $receiptCancellationService,
        private readonly ReceiptMigrationService $receiptMigrationService
    ) {
    }

    public function resolveQueue(array $task): string
    {
        return (string) ($this->executionPlan->resolve($task)['queue'] ?? config('workerhub.queues.default'));
    }

    public function resolveTries(array $task): int
    {
        return (int) ($this->executionPlan->resolve($task)['tries'] ?? 3);
    }

    public function resolveTimeout(array $task): int
    {
        return (int) ($this->executionPlan->resolve($task)['timeout'] ?? 180);
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    public function resolveExecutionPlan(array $task): array
    {
        return $this->executionPlan->resolve($task);
    }

    public function handle(array $task): array
    {
        $type = (string) ($task['type'] ?? '');
        $payload = $task['payload'] ?? [];

        if (!is_array($payload)) {
            throw new WorkerTaskProcessingException('El payload de la tarea debe ser un objeto JSON.', ['task' => $task]);
        }

        $payload['_workerhub_task_id'] = $task['task_id'] ?? null;
        $payload['_workerhub_task_type'] = $type;

        return match ($type) {
            'document_migration' => $this->documentMigrationService->handle($payload),
            'invoice_migration' => $this->invoiceMigrationService->handle($payload),
            'order_cancellation' => $this->orderCancellationService->handle($payload),
            'order_migration' => $this->orderMigrationService->handle($payload),
            'receipt_cancellation' => $this->receiptCancellationService->handle($payload),
            'receipt_migration' => $this->receiptMigrationService->handle($payload),
            default => throw new InvalidArgumentException(sprintf('Tipo de tarea no soportado: %s', $type)),
        };
    }
}
