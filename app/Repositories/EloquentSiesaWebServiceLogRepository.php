<?php

namespace App\Repositories;

use App\Contracts\SiesaWebServiceLogRepositoryInterface;
use App\Data\SiesaWebServiceLogRecord;
use App\Models\SiesaWebService;

class EloquentSiesaWebServiceLogRepository implements SiesaWebServiceLogRepositoryInterface
{
    public function create(array $attributes): SiesaWebServiceLogRecord
    {
        $record = SiesaWebService::query()->create($attributes);

        return new SiesaWebServiceLogRecord(
            (int) $record->getKey(),
            (string) $record->xml,
            is_array($record->context) ? $record->context : null,
        );
    }

    public function markProcessed(SiesaWebServiceLogRecord $record, int $result, string $resultText): void
    {
        SiesaWebService::query()
            ->whereKey($record->id)
            ->update([
                'result' => $result,
                'result_text' => $resultText,
                'processed_at' => now(),
            ]);
    }
}
