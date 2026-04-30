<?php

namespace App\Services\Integrations\Api;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OrderMigrationNotificationClient
{
    public function notifyOrderMigrated(array $task): void
    {
        $payload = Arr::get($task, 'payload', []);
        $createdByUserId = Arr::get($payload, 'created_by_user_id')
            ?? Arr::get($payload, 'metadata.created_by_user_id');

        if (!is_numeric($createdByUserId)) {
            Log::warning('WorkerHub order notification skipped: missing created_by_user_id.', [
                'task_id' => Arr::get($task, 'task_id'),
                'document_id' => Arr::get($payload, 'document_id'),
                'payload_keys' => array_keys($payload),
            ]);
            return;
        }

        $baseUrl = rtrim((string) config('services.api.base_url', ''), '/');
        $endpoint = (string) config('services.api.order_migration_notifications_endpoint', '');
        $token = (string) config('services.api.workerhub_notification_token', '');

        if ($baseUrl === '' || $endpoint === '' || $token === '') {
            Log::warning('WorkerHub order notification skipped: API callback configuration incomplete.', [
                'task_id' => Arr::get($task, 'task_id'),
                'document_id' => Arr::get($payload, 'document_id'),
                'base_url' => $baseUrl,
                'endpoint' => $endpoint,
                'has_token' => $token !== '',
            ]);
            return;
        }

        $url = $baseUrl . '/' . ltrim($endpoint, '/');
        $requestPayload = [
            'task_id' => Arr::get($task, 'task_id'),
            'document_id' => Arr::get($payload, 'document_id'),
            'order_id' => Arr::get($payload, 'order_id'),
            'company_id' => Arr::get($payload, 'company_id'),
            'client_code' => Arr::get($payload, 'client_code'),
            'client_branch' => Arr::get($payload, 'client_branch'),
            'order_total' => Arr::get($payload, 'order_total'),
            'created_by_user_id' => (int) $createdByUserId,
            'completed_at' => now()->toIso8601String(),
            'result' => Arr::get($task, 'result', []),
        ];

        $response = Http::timeout((int) config('services.api.timeout', 10))
            ->acceptJson()
            ->asForm()
            ->withHeaders([
                'X-WorkerHub-Notification-Token' => $token,
            ])
            ->post($url, $requestPayload);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'API order migration notification failed with status %s.',
                $response->status()
            ));
        }
    }
}
