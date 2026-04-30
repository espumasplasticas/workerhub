<?php

namespace App\Contracts;

use App\Data\SiesaWebServiceLogRecord;

interface SiesaWebServiceLogRepositoryInterface
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): SiesaWebServiceLogRecord;

    public function markProcessed(SiesaWebServiceLogRecord $record, int $result, string $resultText): void;
}
