<?php

namespace App\Services\Workers;

use App\Data\OperationLogFilters;
use App\Models\WorkerOperationLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
            'channel' => $this->resolveChannel($request),
            'worker_task_id' => $taskId,
            'context' => $context,
        ]);
    }

    public function listRecent(OperationLogFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->queryLogs($filters)
            ->with('task')
            ->latest()
            ->paginate($perPage);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function export(OperationLogFilters $filters, int $limit = 250): array
    {
        return $this->queryLogs($filters)
            ->with('task')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function (WorkerOperationLog $log): array {
                return [
                    'id' => $log->getKey(),
                    'action' => $log->action,
                    'status' => $log->status,
                    'actor' => $log->actor,
                    'channel' => $log->channel,
                    'worker_task_id' => $log->worker_task_id,
                    'context' => $log->context,
                    'created_at' => $log->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }

    private function resolveActor(Request $request): ?string
    {
        $attributeActor = $request->attributes->get('workerhub_actor');
        if (is_string($attributeActor) && $attributeActor !== '') {
            return $attributeActor;
        }

        if ($request->hasSession()) {
            $sessionOperator = $request->session()->get(config('workerhub.backoffice.session_key', 'workerhub.operator'));
            if (is_array($sessionOperator) && !empty($sessionOperator['email'])) {
                return (string) $sessionOperator['email'];
            }
        }

        $user = $request->user();
        if ($user !== null) {
            return $user->email;
        }

        if ($request->header('X-WorkerHub-Token') !== null || $request->query('token') !== null) {
            return 'token-operator';
        }

        return null;
    }

    private function resolveChannel(Request $request): string
    {
        $attributeChannel = $request->attributes->get('workerhub_access_channel');
        if (is_string($attributeChannel) && $attributeChannel !== '') {
            return $attributeChannel;
        }

        if ($request->header('X-WorkerHub-Token') !== null || $request->query('token') !== null) {
            return 'token_operator';
        }

        return $request->is('api/*') ? 'api' : 'web';
    }

    private function queryLogs(OperationLogFilters $filters): Builder
    {
        return WorkerOperationLog::query()
            ->when($filters->action !== null, fn (Builder $query) => $query->where('action', $filters->action))
            ->when($filters->status !== null, fn (Builder $query) => $query->where('status', $filters->status))
            ->when($filters->actor !== null, fn (Builder $query) => $query->where('actor', $filters->actor))
            ->when($filters->channel !== null, fn (Builder $query) => $query->where('channel', $filters->channel))
            ->when($filters->workerTaskId !== null, fn (Builder $query) => $query->where('worker_task_id', $filters->workerTaskId))
            ->when($filters->dateFrom !== null, fn (Builder $query) => $query->where('created_at', '>=', $filters->dateFrom))
            ->when($filters->dateTo !== null, fn (Builder $query) => $query->where('created_at', '<=', $filters->dateTo));
    }
}
