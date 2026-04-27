<?php

namespace App\Services\Workers;

use App\Models\WorkerTaskDispatchRegistry;
use Illuminate\Database\QueryException;

class WorkerTaskDispatchRegistryService
{
    /**
     * @return array{entity_type:string,entity_id:int|null,document_id:string,event_type:string,source:string|null}|null
     */
    public function resolveRegistryKey(string $taskType, array $payload): ?array
    {
        return match ($taskType) {
            'receipt_migration' => $this->buildKey(
                'receipt',
                isset($payload['receipt_id']) && is_numeric($payload['receipt_id']) ? (int) $payload['receipt_id'] : null,
                (string) ($payload['document_id'] ?? ''),
                'migration',
                isset($payload['source']) ? (string) $payload['source'] : null
            ),
            'order_migration' => $this->buildKey(
                'order',
                isset($payload['order_id']) && is_numeric($payload['order_id']) ? (int) $payload['order_id'] : null,
                (string) ($payload['document_id'] ?? ''),
                'migration',
                isset($payload['source']) ? (string) $payload['source'] : null
            ),
            'invoice_migration' => $this->buildKey(
                'invoice',
                isset($payload['invoice_id']) && is_numeric($payload['invoice_id']) ? (int) $payload['invoice_id'] : null,
                (string) ($payload['document_id'] ?? ''),
                'migration',
                isset($payload['source']) ? (string) $payload['source'] : null
            ),
            default => null,
        };
    }

    public function findAccepted(string $entityType, string $documentId, string $eventType = 'migration'): ?WorkerTaskDispatchRegistry
    {
        return WorkerTaskDispatchRegistry::query()
            ->where('entity_type', $entityType)
            ->where('event_type', $eventType)
            ->where('document_id', $documentId)
            ->first();
    }

    public function recordAccepted(
        string $entityType,
        ?int $entityId,
        string $documentId,
        string $eventType,
        string $taskId,
        ?string $source = null
    ): WorkerTaskDispatchRegistry {
        $attributes = [
            'entity_type' => $entityType,
            'event_type' => $eventType,
            'document_id' => $documentId,
        ];

        $values = [
            'entity_id' => $entityId,
            'task_id' => $taskId,
            'source' => $source,
            'accepted_at' => now(),
        ];

        $existing = WorkerTaskDispatchRegistry::query()
            ->where($attributes)
            ->first();

        if ($existing instanceof WorkerTaskDispatchRegistry) {
            return $existing;
        }

        try {
            return WorkerTaskDispatchRegistry::query()->create(array_merge($attributes, $values));
        } catch (QueryException) {
            return WorkerTaskDispatchRegistry::query()
                ->where($attributes)
                ->firstOrFail();
        }
    }

    public function recordAcceptedForTask(string $taskType, array $payload, string $taskId): ?WorkerTaskDispatchRegistry
    {
        $key = $this->resolveRegistryKey($taskType, $payload);

        if ($key === null) {
            return null;
        }

        return $this->recordAccepted(
            $key['entity_type'],
            $key['entity_id'],
            $key['document_id'],
            $key['event_type'],
            $taskId,
            $key['source']
        );
    }

    /**
     * @return array{entity_type:string,entity_id:int|null,document_id:string,event_type:string,source:string|null}|null
     */
    private function buildKey(string $entityType, ?int $entityId, string $documentId, string $eventType, ?string $source): ?array
    {
        $documentId = trim($documentId);

        if ($documentId === '') {
            return null;
        }

        return [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'document_id' => $documentId,
            'event_type' => $eventType,
            'source' => $source,
        ];
    }
}
