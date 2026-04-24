<?php

namespace App\Services\Kafka;

use App\Jobs\DispatchWorkerTaskJob;
use App\Services\Workers\WorkerTaskRouter;
use App\Services\Workers\WorkerTaskMonitorService;
use Illuminate\Support\Facades\Log;
use longlang\phpkafka\Consumer\ConsumeMessage;
use longlang\phpkafka\Consumer\Consumer;
use Throwable;

class WorkerTaskConsumer
{
    public function __construct(
        private readonly KafkaConfigFactory $configFactory,
        private readonly WorkerTaskRouter $workerTaskRouter,
        private readonly KafkaMessageProducer $producer,
        private readonly WorkerTaskMonitorService $monitor
    ) {
    }

    public function consumeRequests(): void
    {
        $topics = $this->resolveRequestTopics();
        $consumer = new Consumer(
            $this->configFactory->makeConsumerConfig($topics),
            function (ConsumeMessage $message): void {
                $this->handleMessage($message);
            }
        );

        try {
            $consumer->start();
        } finally {
            $consumer->close();
        }
    }

    private function handleMessage(ConsumeMessage $message): void
    {
        $key = $message->getKey();
        $task = null;

        try {
            $body = (string) ($message->getValue() ?? '');
            $task = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($task) || !isset($task['type'])) {
                throw new \InvalidArgumentException('El mensaje Kafka debe contener un objeto JSON con type.');
            }

            $executionPlan = $this->workerTaskRouter->resolveExecutionPlan($task);
            $task['tries'] = (int) ($executionPlan['tries'] ?? $this->workerTaskRouter->resolveTries($task));
            $task['timeout'] = (int) ($executionPlan['timeout'] ?? $this->workerTaskRouter->resolveTimeout($task));

            if (($executionPlan['runtime'] ?? 'php') === 'php') {
                $queue = (string) ($executionPlan['queue'] ?? $this->workerTaskRouter->resolveQueue($task));

                DispatchWorkerTaskJob::dispatch($task)
                    ->onConnection('redis')
                    ->onQueue($queue);

                if (isset($task['task_id']) && is_string($task['task_id'])) {
                    $this->monitor->markQueued($task['task_id'], $queue);
                }

                Log::info('Worker task enqueued from Kafka.', [
                    'type' => $task['type'],
                    'process_key' => $executionPlan['process_key'] ?? null,
                    'runtime' => 'php',
                    'queue' => $queue,
                    'key' => $key,
                    'tries' => $task['tries'],
                    'timeout' => $task['timeout'],
                ]);
            } else {
                $executionTopic = (string) ($executionPlan['execution_topic'] ?? '');

                if ($executionTopic === '') {
                    throw new \RuntimeException('La tarea no tiene topic de ejecucion externo configurado.');
                }

                $externalPayload = array_merge($task, [
                    'execution_plan' => $executionPlan,
                ]);

                $published = $this->producer->publish($executionTopic, $externalPayload, is_scalar($key) ? (string) $key : null, [
                    'workerhub-runtime' => (string) ($executionPlan['runtime'] ?? 'external'),
                    'workerhub-process' => (string) ($executionPlan['process_key'] ?? 'general'),
                    'workerhub-task-id' => (string) ($task['task_id'] ?? ''),
                ]);

                if (!$published) {
                    throw new \RuntimeException(sprintf(
                        'No fue posible delegar la tarea a runtime externo %s.',
                        (string) ($executionPlan['runtime'] ?? 'external')
                    ));
                }

                if (isset($task['task_id']) && is_string($task['task_id'])) {
                    $this->monitor->markQueued($task['task_id'], 'kafka:' . $executionTopic);
                    $this->monitor->addEvent(
                        $task['task_id'],
                        'task.delegated',
                        'Task delegated to external Kafka runtime.',
                        [
                            'runtime' => $executionPlan['runtime'] ?? 'external',
                            'topic' => $executionTopic,
                            'process_key' => $executionPlan['process_key'] ?? null,
                        ]
                    );
                }

                Log::info('Worker task delegated to external runtime.', [
                    'type' => $task['type'],
                    'process_key' => $executionPlan['process_key'] ?? null,
                    'runtime' => $executionPlan['runtime'] ?? 'external',
                    'topic' => $executionTopic,
                    'key' => $key,
                    'tries' => $task['tries'],
                    'timeout' => $task['timeout'],
                ]);
            }

            $message->getConsumer()->ack($message);
        } catch (Throwable $exception) {
            Log::error('Unable to enqueue worker task from Kafka.', [
                'error' => $exception->getMessage(),
                'key' => $key,
            ]);

            if (is_array($task) && isset($task['task_id']) && is_string($task['task_id'])) {
                $this->monitor->markRejected($task['task_id'], $exception->getMessage());
            }

            $this->producer->publishFailure([
                'event' => 'worker_task.rejected',
                'status' => 'rejected',
                'key' => $key,
                'reason' => $exception->getMessage(),
                'received_at' => now()->toIso8601String(),
            ], $key);

            $this->producer->publishDeadLetter([
                'event' => 'worker_task.dead_letter',
                'status' => 'rejected',
                'key' => $key,
                'task' => $task,
                'reason' => $exception->getMessage(),
                'received_at' => now()->toIso8601String(),
            ], $key);

            $message->getConsumer()->ack($message);
        }
    }

    /**
     * @return array<int, string>
     */
    private function resolveRequestTopics(): array
    {
        $topics = [];
        $defaultTopic = trim((string) config('workerhub.kafka.topics.requests', ''));

        if ($defaultTopic !== '') {
            $topics[] = $defaultTopic;
        }

        foreach ((array) config('workerhub.processes', []) as $processDefinition) {
            $requestTopic = trim((string) data_get($processDefinition, 'topics.requests', ''));

            if ($requestTopic !== '') {
                $topics[] = $requestTopic;
            }
        }

        $topics = array_values(array_unique($topics));

        if ($topics === []) {
            $topics[] = 'workerhub.tasks.requests';
        }

        return $topics;
    }
}
