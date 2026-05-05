<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class WorkerTaskProcessCatalog
{
    public function __construct(private readonly Repository $config)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        $definitions = (array) $this->config->get('workerhub.processes', []);

        return array_map(
            static fn (string $key, array $definition): array => array_merge([
                'key' => $key,
                'label' => Str::headline(str_replace('_', ' ', $key)),
                'description' => null,
                'keywords' => [],
            ], $definition),
            array_keys($definitions),
            array_values($definitions)
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string|null>
     */
    public function resolve(string $type, array $payload, ?string $source = null): array
    {
        $metadata = Arr::get($payload, 'metadata', []);
        $metadata = is_array($metadata) ? $metadata : [];

        $explicitKey = $this->normalizeString(
            $payload['process_key'] ?? $metadata['process_key'] ?? $metadata['process'] ?? null
        );
        $explicitLabel = $this->normalizeString(
            $payload['process_label'] ?? $metadata['process_label'] ?? null
        );
        $scheduleName = $this->normalizeString(
            $payload['schedule_name'] ?? $metadata['schedule_name'] ?? $metadata['windows_task_name'] ?? null
        );
        $taskName = $this->normalizeString(
            $payload['task_name'] ?? $metadata['task_name'] ?? null
        );

        $definitions = collect($this->definitions())->keyBy('key');

        if ($explicitKey !== null && $definitions->has($explicitKey)) {
            $definition = (array) $definitions->get($explicitKey);

            return [
                'process_key' => $explicitKey,
                'process_label' => $explicitLabel ?? (string) ($definition['label'] ?? $explicitKey),
                'schedule_name' => $scheduleName,
                'task_name' => $taskName,
            ];
        }

        $haystack = Str::lower(implode(' ', array_filter([
            $type,
            $source,
            Arr::get($payload, 'document_id'),
            $scheduleName,
            $taskName,
            $explicitLabel,
            $metadata['job_name'] ?? null,
            $metadata['command_name'] ?? null,
        ], static fn ($value) => is_scalar($value) && trim((string) $value) !== '')));

        foreach ($this->definitions() as $definition) {
            $keywords = array_map(
                static fn (mixed $keyword): string => Str::lower((string) $keyword),
                (array) ($definition['keywords'] ?? [])
            );

            foreach ($keywords as $keyword) {
                if ($keyword !== '' && Str::contains($haystack, $keyword)) {
                    return [
                        'process_key' => (string) $definition['key'],
                        'process_label' => $explicitLabel ?? (string) ($definition['label'] ?? $definition['key']),
                        'schedule_name' => $scheduleName,
                        'task_name' => $taskName,
                    ];
                }
            }
        }

        $fallback = (array) ($definitions->get('general') ?? [
            'key' => 'general',
            'label' => 'General',
        ]);

        return [
            'process_key' => (string) ($fallback['key'] ?? 'general'),
            'process_label' => $explicitLabel ?? (string) ($fallback['label'] ?? 'General'),
            'schedule_name' => $scheduleName,
            'task_name' => $taskName,
        ];
    }

    private function normalizeString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
