<?php

namespace App\Console\Commands;

use App\Jobs\DispatchWorkerTaskJob;
use App\Models\WorkerTask;
use App\Services\Workers\WorkerTaskMonitorService;
use App\Services\Workers\WorkerTaskRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DispatchStalledTasksCommand extends Command
{
    protected $signature = 'workerhub:dispatch-stalled
                            {--type= : Filtrar por tipo de tarea (ej: receipt_migration, order_migration)}
                            {--limit=50 : Máximo de tareas a despachar}
                            {--dry-run : Simular sin despachar realmente}';

    protected $description = 'Despacha directamente a Redis las tareas publicadas a Kafka pero nunca procesadas (published, attempts=0).';

    public function handle(
        WorkerTaskRouter $router,
        WorkerTaskMonitorService $monitor
    ): int {
        $type    = $this->option('type');
        $limit   = (int) $this->option('limit');
        $dryRun  = (bool) $this->option('dry-run');

        $query = WorkerTask::query()
            ->where('status', 'published')
            ->where('attempts', 0)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderBy('requested_at')
            ->limit($limit);

        $tasks = $query->get();

        if ($tasks->isEmpty()) {
            $this->info('No hay tareas en estado "published" con attempts=0' . ($type ? " del tipo [{$type}]" : '') . '.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Se encontraron %d tarea(s) a despachar%s%s.',
            $tasks->count(),
            $type ? " de tipo [{$type}]" : '',
            $dryRun ? ' [DRY-RUN]' : ''
        ));

        $dispatched = 0;
        $errors     = 0;

        foreach ($tasks as $task) {
            /** @var WorkerTask $task */
            $payload = is_array($task->payload) ? $task->payload : [];
            $taskId  = (string) $task->getKey();

            $message = [
                'task_id'      => $taskId,
                'type'         => $task->type,
                'priority'     => $task->priority ?? 'default',
                'headers'      => [],
                'payload'      => $payload,
                'submitted_at' => $task->requested_at?->toIso8601String() ?? now()->toIso8601String(),
            ];

            try {
                $executionPlan = $router->resolveExecutionPlan($message);
                $queue = (string) ($executionPlan['queue'] ?? config('workerhub.queues.default', 'migration-default'));

                $jobPayload = array_merge($message, [
                    'tries'   => (int) ($executionPlan['tries']   ?? 3),
                    'timeout' => (int) ($executionPlan['timeout'] ?? 300),
                    'queue'   => $queue,
                ]);

                $this->line(sprintf(
                    '  [%s] type=%-25s key=%-20s queue=%s%s',
                    Str::substr($taskId, 0, 8),
                    $task->type,
                    (string) ($payload['document_id'] ?? 'N/A'),
                    $queue,
                    $dryRun ? ' (skipped)' : ''
                ));

                if (!$dryRun) {
                    DispatchWorkerTaskJob::dispatch($jobPayload)
                        ->onConnection('redis')
                        ->onQueue($queue);

                    $monitor->markQueued($taskId, $queue);
                }

                $dispatched++;
            } catch (\Throwable $e) {
                $this->error(sprintf('  Error despachando [%s]: %s', Str::substr($taskId, 0, 8), $e->getMessage()));
                $errors++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Resultado: %d despachadas, %d con error.%s',
            $dispatched,
            $errors,
            $dryRun ? ' (dry-run, nada fue enviado a Redis)' : ''
        ));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
