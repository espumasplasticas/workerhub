<?php

namespace App\Services\Workers;

use App\Jobs\DispatchWorkerTaskJob;
use App\Services\Kafka\KafkaMessageProducer;

class WorkerTaskDispatchService
{
    public function __construct(
        private readonly KafkaMessageProducer $producer,
        private readonly WorkerTaskRouter $router
    ) {
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $headers
     * @return array{mode: string, topic: string, queue: string|null}
     */
    public function dispatch(string $topic, array $message, ?string $key = null, array $headers = []): array
    {
        $published = $this->producer->publish($topic, $message, $key, $headers);

        if ($published) {
            return [
                'mode' => 'kafka',
                'topic' => $topic,
                'queue' => null,
            ];
        }

        $queue = $this->router->resolveQueue($message);
        $payload = array_merge($message, [
            'tries' => $this->router->resolveTries($message),
            'timeout' => $this->router->resolveTimeout($message),
        ]);

        DispatchWorkerTaskJob::dispatch($payload)->onQueue($queue);

        return [
            'mode' => 'direct_queue',
            'topic' => $topic,
            'queue' => $queue,
        ];
    }
}
