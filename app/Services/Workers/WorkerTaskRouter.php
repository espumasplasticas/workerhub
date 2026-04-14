<?php

namespace App\Services\Workers;

use App\Exceptions\WorkerTaskProcessingException;
use InvalidArgumentException;

class WorkerTaskRouter
{
    public function __construct(
        private readonly DocumentMigrationService $documentMigrationService,
        private readonly ReceiptMigrationService $receiptMigrationService
    ) {
    }

    public function resolveQueue(array $task): string
    {
        $type = (string) ($task['type'] ?? '');
        $priority = strtolower((string) ($task['priority'] ?? 'default'));

        return match ($type) {
            'document_migration' => $priority === 'high'
                ? (string) config('workerhub.tasks.document_migration.high_priority_queue')
                : (string) config('workerhub.tasks.document_migration.queue'),
            'receipt_migration' => $priority === 'high'
                ? (string) config('workerhub.tasks.receipt_migration.high_priority_queue')
                : (string) config('workerhub.tasks.receipt_migration.queue'),
            default => (string) config('workerhub.queues.default'),
        };
    }

    public function resolveTries(array $task): int
    {
        return match ((string) ($task['type'] ?? '')) {
            'document_migration' => (int) config('workerhub.tasks.document_migration.tries', 3),
            'receipt_migration' => (int) config('workerhub.tasks.receipt_migration.tries', 3),
            default => 3,
        };
    }

    public function resolveTimeout(array $task): int
    {
        return match ((string) ($task['type'] ?? '')) {
            'document_migration' => (int) config('workerhub.tasks.document_migration.timeout', 300),
            'receipt_migration' => (int) config('workerhub.tasks.receipt_migration.timeout', 300),
            default => 180,
        };
    }

    public function handle(array $task): array
    {
        $type = (string) ($task['type'] ?? '');
        $payload = $task['payload'] ?? [];

        if (!is_array($payload)) {
            throw new WorkerTaskProcessingException('El payload de la tarea debe ser un objeto JSON.', ['task' => $task]);
        }

        return match ($type) {
            'document_migration' => $this->documentMigrationService->handle($payload),
            'receipt_migration' => $this->receiptMigrationService->handle($payload),
            default => throw new InvalidArgumentException(sprintf('Tipo de tarea no soportado: %s', $type)),
        };
    }
}
