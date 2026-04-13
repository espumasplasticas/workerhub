<?php

namespace App\Services\Workers;

use App\Models\WorkerOperationLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class WorkerOperationLogService
{
    public function record(
        Request $request,
        string $action,
        string $status = 'success',
        ?string $taskId = null,
        array $context = []
    ): WorkerOperationLog {
        return WorkerOperationLog::query()->create([
            'action' => $action,
            'status' => $status,
            'actor' => $this->resolveActor($request),
            'channel' => $request->is('api/*') ? 'api' : 'web',
            'worker_task_id' => $taskId,
            'context' => $context,
        ]);
    }

    public function listRecent(int $perPage = 25): LengthAwarePaginator
    {
        return WorkerOperationLog::query()
            ->with('task')
            ->latest()
            ->paginate($perPage);
    }

    private function resolveActor(Request $request): ?string
    {
        $user = $request->user();
        if ($user !== null) {
            return $user->email;
        }

        if ($request->header('X-WorkerHub-Token') !== null || $request->query('token') !== null) {
            return 'token-operator';
        }

        return null;
    }
}
