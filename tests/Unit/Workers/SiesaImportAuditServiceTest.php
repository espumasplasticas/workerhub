<?php

namespace Tests\Unit\Workers;

use App\Contracts\SiesaWebServiceLogRepositoryInterface;
use App\Data\SiesaWebServiceLogRecord;
use App\Support\NullFlatFileWriter;
use App\Services\Workers\SiesaImportAuditService;
use Epsalibrary\Application\Imports\ImportBatchBuilder;
use Epsalibrary\Contracts\ImportManagerInterface;
use Epsalibrary\Results\ImportResult;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SiesaImportAuditServiceTest extends TestCase
{
    public function test_it_persists_xml_before_importing_and_marks_success_afterward(): void
    {
        $builder = new ImportBatchBuilder();

        $repository = Mockery::mock(SiesaWebServiceLogRepositoryInterface::class);
        $repository->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $attributes) use ($builder): bool {
                $expectedXml = $builder
                    ->build(['0200...'], new NullFlatFileWriter(), false)
                    ->payload;

                return $attributes['worker_task_id'] === 'task-1'
                    && $attributes['task_type'] === 'receipt_migration'
                    && $attributes['document_id'] === '002-FC-24090'
                    && $attributes['import_stage'] === 'receipt_migration'
                    && $attributes['xml'] === $expectedXml
                    && $attributes['result'] === null;
            }))
            ->andReturn(new SiesaWebServiceLogRecord(401, 'xml', ['import_stage' => 'receipt_migration']));
        $repository->shouldReceive('markProcessed')
            ->once()
            ->with(
                Mockery::on(static fn (SiesaWebServiceLogRecord $record): bool => $record->id === 401),
                1,
                Mockery::on(static fn (string $resultText): bool => str_contains($resultText, 'Importacion exitosa'))
            );

        $importManager = Mockery::mock(ImportManagerInterface::class);
        $importManager->shouldReceive('import')
            ->once()
            ->with(['0200...'])
            ->andReturn(new ImportResult(true, 'Importacion exitosa', [], '<Envelope />'));

        $service = new SiesaImportAuditService($importManager, $repository, $builder);

        $audit = $service->import(['0200...'], [
            'worker_task_id' => 'task-1',
            'task_type' => 'receipt_migration',
            'document_id' => '002-FC-24090',
            'import_stage' => 'receipt_migration',
        ]);

        $this->assertSame(401, $audit->log->id);
        $this->assertTrue($audit->result->success);
    }

    public function test_it_marks_the_log_as_failed_when_import_throws(): void
    {
        $builder = new ImportBatchBuilder();

        $repository = Mockery::mock(SiesaWebServiceLogRepositoryInterface::class);
        $repository->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $attributes) use ($builder): bool {
                $expectedXml = $builder
                    ->build(['0200...'], new NullFlatFileWriter(), false)
                    ->payload;

                return $attributes['document_id'] === '002-FC-24091'
                    && $attributes['import_stage'] === 'receipt_customer_sync'
                    && $attributes['xml'] === $expectedXml;
            }))
            ->andReturn(new SiesaWebServiceLogRecord(402, 'xml', ['import_stage' => 'receipt_customer_sync']));
        $repository->shouldReceive('markProcessed')
            ->once()
            ->with(
                Mockery::on(static fn (SiesaWebServiceLogRecord $record): bool => $record->id === 402),
                0,
                'SOAP timeout'
            );

        $importManager = Mockery::mock(ImportManagerInterface::class);
        $importManager->shouldReceive('import')
            ->once()
            ->with(['0200...'])
            ->andThrow(new RuntimeException('SOAP timeout'));

        $service = new SiesaImportAuditService($importManager, $repository, $builder);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SOAP timeout');

        $service->import(['0200...'], [
            'worker_task_id' => 'task-2',
            'task_type' => 'receipt_migration',
            'document_id' => '002-FC-24091',
            'import_stage' => 'receipt_customer_sync',
        ]);
    }
}
