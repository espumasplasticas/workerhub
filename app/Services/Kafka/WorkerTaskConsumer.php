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
        $topic = (string) config('workerhub.kafka.topics.requests');
        $consumer = new Consumer(
            $this->configFactory->makeConsumerConfig($topic),
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

            $queue = $this->workerTaskRouter->resolveQueue($task);
            $task['tries'] = $this->workerTaskRouter->resolveTries($task);
            $task['timeout'] = $this->workerTaskRouter->resolveTimeout($task);

            DispatchWorkerTaskJob::dispatch($task)
                ->onConnection('redis')
                ->onQueue($queue);

            if (isset($task['task_id']) && is_string($task['task_id'])) {
                $this->monitor->markQueued($task['task_id'], $queue);
            }

            Log::info('Worker task enqueued from Kafka.', [
                'type' => $task['type'],
                'queue' => $queue,
                'key' => $key,
                'tries' => $task['tries'],
                'timeout' => $task['timeout'],
            ]);

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
}
