<?php

namespace App\Services\Kafka;

use Illuminate\Support\Facades\Log;
use longlang\phpkafka\Producer\Producer;
use Throwable;

class KafkaMessageProducer
{
    public function __construct(private readonly KafkaConfigFactory $configFactory)
    {
    }

    public function publish(string $topic, array $message, ?string $key = null, array $headers = []): bool
    {
        if (!$this->isPublishEnabled()) {
            return false;
        }

        $producer = new Producer($this->configFactory->makeProducerConfig());

        try {
            $producer->send(
                $topic,
                json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $key,
                $this->normalizeHeaders($headers)
            );
            return true;
        } catch (Throwable $exception) {
            if ($this->shouldSuppressPublishFailure()) {
                Log::warning('WorkerHub Kafka publish skipped after failure.', [
                    'topic' => $topic,
                    'task_id' => $message['task_id'] ?? null,
                    'type' => $message['type'] ?? null,
                    'error' => $exception->getMessage(),
                ]);

                return false;
            }

            throw $exception;
        } finally {
            $producer->close();
        }
    }

    public function publishResult(array $message, ?string $key = null): bool
    {
        return $this->publish((string) config('workerhub.kafka.topics.results'), $message, $key);
    }

    public function publishFailure(array $message, ?string $key = null): bool
    {
        return $this->publish((string) config('workerhub.kafka.topics.failures'), $message, $key);
    }

    public function publishDeadLetter(array $message, ?string $key = null): bool
    {
        return $this->publish((string) config('workerhub.kafka.topics.dead_letters'), $message, $key);
    }

    private function isPublishEnabled(): bool
    {
        return (bool) config('workerhub.kafka.publish_enabled', true);
    }

    private function shouldSuppressPublishFailure(): bool
    {
        return (bool) config('workerhub.kafka.suppress_publish_failures', false)
            || (bool) config('workerhub.kafka.direct_dispatch_fallback', false);
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (is_scalar($value) || $value === null) {
                $normalized[(string) $name] = $value === null ? '' : (string) $value;
                continue;
            }

            $normalized[(string) $name] = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        return $normalized;
    }
}
