<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;

class WorkerTaskExecutionPlanResolver
{
    public function __construct(
        private readonly WorkerTaskProcessCatalog $processCatalog,
        private readonly Repository $config
    ) {
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    public function resolve(array $task): array
    {
        $type = (string) ($task['type'] ?? '');
        $payload = $task['payload'] ?? [];
        $payload = is_array($payload) ? $payload : [];
        $priority = strtolower((string) ($task['priority'] ?? 'default'));
        $source = Arr::get($payload, 'source');

        $process = $this->processCatalog->resolve($type, $payload, is_scalar($source) ? (string) $source : null);
        $processKey = (string) ($process['process_key'] ?? 'general');
        $processConfig = (array) $this->config->get("workerhub.processes.$processKey", []);

        $runtime = (string) ($processConfig['runtime'] ?? 'php');
        $queues = (array) ($processConfig['queues'] ?? []);
        $topics = (array) ($processConfig['topics'] ?? []);

        $defaultQueue = $this->fallbackTaskQueue($type, false);
        $highPriorityQueue = $this->fallbackTaskQueue($type, true);
        $queue = $priority === 'high'
            ? (string) ($queues['high'] ?? $highPriorityQueue)
            : (string) ($queues['default'] ?? $defaultQueue);

        $tries = (int) ($processConfig['tries'] ?? $this->config->get("workerhub.tasks.$type.tries", 3));
        $timeout = (int) ($processConfig['timeout'] ?? $this->config->get("workerhub.tasks.$type.timeout", 180));

        $requestTopic = (string) ($topics['requests'] ?? $this->config->get('workerhub.kafka.topics.requests'));
        $executionTopic = (string) ($topics['execution'] ?? $this->fallbackExecutionTopic($processKey, $runtime));

        return array_merge($process, [
            'runtime' => $runtime,
            'queue' => $queue,
            'high_priority_queue' => (string) ($queues['high'] ?? $highPriorityQueue),
            'request_topic' => $requestTopic,
            'execution_topic' => $executionTopic,
            'tries' => $tries,
            'timeout' => $timeout,
        ]);
    }

    private function fallbackTaskQueue(string $type, bool $highPriority): string
    {
        $configKey = $highPriority ? 'high_priority_queue' : 'queue';
        $defaultQueue = $highPriority
            ? $this->config->get('workerhub.queues.high_priority')
            : $this->config->get('workerhub.queues.default');

        return (string) $this->config->get("workerhub.tasks.$type.$configKey", $defaultQueue);
    }

    private function fallbackExecutionTopic(string $processKey, string $runtime): string
    {
        $prefix = (string) $this->config->get('workerhub.kafka.topics.external_execution_prefix', 'workerhub.runtime');

        return sprintf('%s.%s.%s', trim($prefix, '.'), trim($runtime, '.'), trim($processKey, '.'));
    }
}
