<?php

namespace Tests\Unit;

use App\Exceptions\WorkerTaskProcessingException;
use App\Services\Workers\DocumentMigrationService;
use Epsalibrary\Contracts\ImportManagerInterface;
use Epsalibrary\Results\ImportResult;
use Mockery;
use Tests\TestCase;

class DocumentMigrationServiceTest extends TestCase
{
    public function test_it_rejects_document_migration_when_soap_configuration_is_missing(): void
    {
        config()->set('epsa_library.soap.url', '');
        config()->set('epsa_library.soap.user', '');
        config()->set('epsa_library.soap.password', '');
        config()->set('epsa_library.soap.connection', '');

        $importManager = Mockery::mock(ImportManagerInterface::class);
        $importManager->shouldNotReceive('import');

        $service = $this->app->make(DocumentMigrationService::class, [
            'importManager' => $importManager,
        ]);

        $this->expectException(WorkerTaskProcessingException::class);
        $this->expectExceptionMessage('La configuracion SOAP de epsa_library esta incompleta.');

        $service->handle([
            'document_id' => 'DOC-E2E-002',
            'lines' => ['<Linea>00000010</Linea>'],
        ]);
    }

    public function test_it_rejects_document_migration_when_soap_url_is_not_absolute(): void
    {
        config()->set('epsa_library.soap.url', '/');
        config()->set('epsa_library.soap.user', 'user');
        config()->set('epsa_library.soap.password', 'secret');
        config()->set('epsa_library.soap.connection', 'UNOEE');

        $importManager = Mockery::mock(ImportManagerInterface::class);
        $importManager->shouldNotReceive('import');

        $service = $this->app->make(DocumentMigrationService::class, [
            'importManager' => $importManager,
        ]);

        $this->expectException(WorkerTaskProcessingException::class);
        $this->expectExceptionMessage('EPSA_SIESA_SOAP_URL debe ser una URL absoluta HTTP(S)');

        $service->handle([
            'document_id' => 'DOC-E2E-002',
            'lines' => ['<Linea>00000010</Linea>'],
        ]);
    }

    public function test_it_imports_document_migration_when_configuration_is_valid(): void
    {
        config()->set('epsa_library.soap.url', 'https://siesa.test/ws');
        config()->set('epsa_library.soap.user', 'user');
        config()->set('epsa_library.soap.password', 'secret');
        config()->set('epsa_library.soap.connection', 'UNOEE');

        $importManager = Mockery::mock(ImportManagerInterface::class);
        $importManager->shouldReceive('import')
            ->once()
            ->with(['<Linea>00000010</Linea>'])
            ->andReturn(new ImportResult(true, 'Importacion exitosa', [], '<Envelope />'));

        $service = $this->app->make(DocumentMigrationService::class, [
            'importManager' => $importManager,
        ]);

        $result = $service->handle([
            'document_id' => 'DOC-E2E-002',
            'source' => 'monitor',
            'lines' => ['<Linea>00000010</Linea>'],
        ]);

        $this->assertSame('DOC-E2E-002', $result['document_id']);
        $this->assertSame('Importacion exitosa', $result['message']);
        $this->assertSame(1, $result['line_count']);
    }
}
