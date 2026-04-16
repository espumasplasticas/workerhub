<?php

namespace App\Services\Integrations\Api;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ReceiptMigrationNotificationClient
{
    public function notifyReceiptMigrated(array $task): void
    {
        $payload = Arr::get($task, 'payload', []);
        $createdByUserId = Arr::get($payload, 'created_by_user_id');

        if (!is_numeric($createdByUserId)) {
            return;
        }

        $baseUrl = rtrim((string) config('services.api.base_url', ''), '/');
        $endpoint = (string) config('services.api.receipt_migration_notifications_endpoint', '');
        $token = (string) config('services.api.workerhub_notification_token', '');

        if ($baseUrl === '' || $endpoint === '' || $token === '') {
            return;
        }

        $url = $baseUrl . '/' . ltrim($endpoint, '/');
        $response = Http::timeout((int) config('services.api.timeout', 10))
            ->acceptJson()
            ->withHeaders([
                'X-WorkerHub-Notification-Token' => $token,
            ])
            ->post($url, [
                'task_id' => Arr::get($task, 'task_id'),
                'document_id' => Arr::get($payload, 'document_id'),
                'receipt_id' => Arr::get($payload, 'receipt_id'),
                'company_id' => Arr::get($payload, 'company_id'),
                'client_code' => Arr::get($payload, 'client_code'),
                'client_branch' => Arr::get($payload, 'client_branch'),
                'receipt_total' => Arr::get($payload, 'receipt_total'),
                'created_by_user_id' => (int) $createdByUserId,
                'completed_at' => now()->toIso8601String(),
                'result' => Arr::get($task, 'result', []),
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'API receipt migration notification failed with status %s.',
                $response->status()
            ));
        }
    }
}