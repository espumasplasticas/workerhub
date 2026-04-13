<?php

namespace App\Services\Kafka;

use longlang\phpkafka\Producer\Producer;

class KafkaMessageProducer
{
    public function __construct(private readonly KafkaConfigFactory $configFactory)
    {
    }

    public function publish(string $topic, array $message, ?string $key = null, array $headers = []): void
    {
        $producer = new Producer($this->configFactory->makeProducerConfig());

        try {
            $producer->send(
                $topic,
                json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $key,
                $this->normalizeHeaders($headers)
            );
        } finally {
            $producer->close();
        }
    }

    public function publishResult(array $message, ?string $key = null): void
    {
        $this->publish((string) config('workerhub.kafka.topics.results'), $message, $key);
    }

    public function publishFailure(array $message, ?string $key = null): void
    {
        $this->publish((string) config('workerhub.kafka.topics.failures'), $message, $key);
    }

    public function publishDeadLetter(array $message, ?string $key = null): void
    {
        $this->publish((string) config('workerhub.kafka.topics.dead_letters'), $message, $key);
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
