<?php

namespace App\Data;

use Carbon\CarbonImmutable;

final class OperationLogFilters
{
    public function __construct(
        public readonly ?string $action,
        public readonly ?string $status,
        public readonly ?string $actor,
        public readonly ?string $channel,
        public readonly ?string $workerTaskId,
        public readonly ?CarbonImmutable $dateFrom,
        public readonly ?CarbonImmutable $dateTo,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function fromArray(array $filters): self
    {
        return new self(
            self::normalizeString($filters['action'] ?? null),
            self::normalizeString($filters['status'] ?? null),
            self::normalizeString($filters['actor'] ?? null),
            self::normalizeString($filters['channel'] ?? null),
            self::normalizeString($filters['worker_task_id'] ?? null),
            self::parseDate($filters['date_from'] ?? null, false),
            self::parseDate($filters['date_to'] ?? null, true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'status' => $this->status,
            'actor' => $this->actor,
            'channel' => $this->channel,
            'worker_task_id' => $this->workerTaskId,
            'date_from' => $this->dateFrom?->toDateString(),
            'date_to' => $this->dateTo?->toDateString(),
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
