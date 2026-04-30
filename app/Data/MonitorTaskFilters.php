<?php

namespace App\Data;

use Carbon\CarbonImmutable;

final class MonitorTaskFilters
{
    public function __construct(
        public readonly ?string $status,
        public readonly ?string $type,
        public readonly ?string $source,
        public readonly ?string $processKey,
        public readonly ?string $scheduleName,
        public readonly ?string $priority,
        public readonly ?string $queue,
        public readonly string $replayMode,
        public readonly string $errorMode,
        public readonly ?CarbonImmutable $dateFrom,
        public readonly ?CarbonImmutable $dateTo,
        public readonly bool $onlyDeadLetters,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function fromArray(array $filters, bool $onlyDeadLetters = false): self
    {
        return new self(
            self::normalizeString($filters['status'] ?? null),
            self::normalizeString($filters['type'] ?? null),
            self::normalizeString($filters['source'] ?? null),
            self::normalizeString($filters['process_key'] ?? null),
            self::normalizeString($filters['schedule_name'] ?? null),
            self::normalizeString($filters['priority'] ?? null),
            self::normalizeString($filters['queue'] ?? null),
            self::normalizeEnum($filters['replay_mode'] ?? 'all', ['all', 'replays', 'originals'], 'all'),
            self::normalizeEnum($filters['error_mode'] ?? 'all', ['all', 'with_error', 'without_error'], 'all'),
            self::parseDate($filters['date_from'] ?? null, false),
            self::parseDate($filters['date_to'] ?? null, true),
            $onlyDeadLetters || filter_var($filters['only_dead_letters'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'type' => $this->type,
            'source' => $this->source,
            'process_key' => $this->processKey,
            'schedule_name' => $this->scheduleName,
            'priority' => $this->priority,
            'queue' => $this->queue,
            'replay_mode' => $this->replayMode,
            'error_mode' => $this->errorMode,
            'date_from' => $this->dateFrom?->toDateString(),
            'date_to' => $this->dateTo?->toDateString(),
            'only_dead_letters' => $this->onlyDeadLetters,
        ];
    }

    private static function normalizeString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<int, string> $allowedValues
     */
    private static function normalizeEnum(mixed $value, array $allowedValues, string $default): string
    {
        if (!is_string($value)) {
            return $default;
        }

        $value = trim($value);

        return in_array($value, $allowedValues, true) ? $value : $default;
    }

    private static function parseDate(mixed $value, bool $endOfDay): ?CarbonImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($value);

            return $endOfDay ? $date->endOfDay() : $date->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
