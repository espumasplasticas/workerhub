<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Workers\WorkerTaskMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WorkerTaskLifecycleController extends Controller
{
    public function __construct(private readonly WorkerTaskMonitorService $monitor)
    {
    }

    public function update(Request $request, string $taskId): JsonResponse
    {
        $this->authorizeRuntimeToken($request);

        $validated = $request->validate([
            'status' => ['required', 'in:queued,processing,completed,failed,rejected'],
            'queue' => ['nullable', 'string'],
            'attempts' => ['nullable', 'integer', 'min:0'],
            'message' => ['nullable', 'string'],
            'result' => ['nullable', 'array'],
            'context' => ['nullable', 'array'],
        ]);

        $status = $validated['status'];
        $attempts = (int) ($validated['attempts'] ?? 0);
        $message = (string) ($validated['message'] ?? '');
        $context = $validated['context'] ?? [];
        $context = is_array($context) ? $context : [];

        match ($status) {
            'queued' => $this->monitor->markQueued($taskId, (string) ($validated['queue'] ?? 'external')),
            'processing' => $this->monitor->markProcessing($taskId, $attempts > 0 ? $attempts : 1),
            'completed' => $this->monitor->markCompleted($taskId, $validated['result'] ?? []),
            'failed' => $this->monitor->markFailed($taskId, $message !== '' ? $message : 'External worker reported failure.', $context, $attempts),
            'rejected' => $this->monitor->markRejected($taskId, $message !== '' ? $message : 'External worker rejected task.', $context),
        };

        return response()->json([
            'accepted' => true,
            'task_id' => $taskId,
            'status' => $status,
        ]);
    }

    private function authorizeRuntimeToken(Request $request): void
    {
        $configuredToken = trim((string) config('workerhub.operations.runtime_shared_token', ''));
        $requestToken = trim((string) $request->header('X-WorkerHub-Shared-Token', ''));

        if ($configuredToken === '' || $requestToken === '' || !hash_equals($configuredToken, $requestToken)) {
            throw new HttpException(403, 'Runtime token invalido.');
        }
    }
}
