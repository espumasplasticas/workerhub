<?php

namespace App\Services\Workers;

use App\Contracts\SiesaWebServiceLogRepositoryInterface;
use App\Data\SiesaWebServiceLogRecord;
use App\Support\NullFlatFileWriter;
use Epsalibrary\Application\Imports\ImportBatchBuilder;
use Epsalibrary\Contracts\ImportManagerInterface;
use Epsalibrary\Results\ImportResult;
use Throwable;

class SiesaImportAuditService
{
    public function __construct(
        private readonly ImportManagerInterface $importManager,
        private readonly SiesaWebServiceLogRepositoryInterface $repository,
        private readonly ImportBatchBuilder $batchBuilder,
    ) {
    }

    /**
     * @param list<string> $lines
     * @param array<string, mixed> $context
     */
    public function import(array $lines, array $context): SiesaImportAuditResult
    {
        $xml = $this->buildXml($lines);
        $record = $this->repository->create([
            'worker_task_id' => $context['worker_task_id'] ?? null,
            'task_type' => $context['task_type'] ?? null,
            'document_id' => $context['document_id'] ?? null,
            'source' => $context['source'] ?? null,
            'import_stage' => $context['import_stage'] ?? null,
            'context' => $context,
            'xml' => $xml,
            'result' => null,
            'result_text' => 'Pendiente de importacion.',
            'ts' => now(),
        ]);

        try {
            $result = $this->importManager->import($lines);
            $this->repository->markProcessed(
                $record,
                $result->success ? 1 : 0,
                $this->resultText($result)
            );

            return new SiesaImportAuditResult($record, $result);
        } catch (Throwable $exception) {
            $this->repository->markProcessed($record, 0, $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * @param list<string> $lines
     */
    public function buildXml(array $lines): string
    {
        return $this->batchBuilder
            ->build($lines, new NullFlatFileWriter(), false)
            ->payload;
    }

    private function resultText(ImportResult $result): string
    {
        $parts = [$result->message];

        if ($result->errors !== []) {
            $parts[] = json_encode($result->errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return trim(implode(' | ', array_filter($parts, static fn (?string $value): bool => $value !== null && $value !== '')));
    }
}
