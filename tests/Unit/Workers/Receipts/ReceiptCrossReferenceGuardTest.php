<?php

namespace Tests\Unit\Workers\Receipts;

use App\Contracts\ReceiptCrossReferenceDataSourceInterface;
use App\Data\Receipts\ReceiptCrossReferenceSnapshot;
use App\Exceptions\WorkerTaskProcessingException;
use App\Services\Workers\Receipts\ReceiptCrossReferenceGuard;
use Mockery;
use stdClass;
use Tests\TestCase;

class ReceiptCrossReferenceGuardTest extends TestCase
{
    public function test_it_returns_the_snapshot_when_the_cross_reference_exists(): void
    {
        config()->set('workerhub.receipts.cross_reference.enabled', true);

        $dataSource = Mockery::mock(ReceiptCrossReferenceDataSourceInterface::class);
        $dataSource->shouldReceive('fetch')
            ->once()
            ->andReturn(new ReceiptCrossReferenceSnapshot(
                auxiliaryId: '28050505',
                operationalCenter: 'A41',
                unit: '02',
                branch: '001',
                documentType: 'RFC',
                documentNumber: '00024089',
                exists: true,
            ));

        $guard = new ReceiptCrossReferenceGuard($dataSource, app('config'));

        $snapshot = $guard->assertExists(['document_id' => '002-FC-24089'], new stdClass());

        $this->assertTrue($snapshot->exists);
        $this->assertSame('A41', $snapshot->operationalCenter);
    }

    public function test_it_stops_the_receipt_when_the_cross_reference_does_not_exist(): void
    {
        config()->set('workerhub.receipts.cross_reference.enabled', true);
        config()->set('workerhub.receipts.cross_reference.mode', 'strict');

        $dataSource = Mockery::mock(ReceiptCrossReferenceDataSourceInterface::class);
        $dataSource->shouldReceive('fetch')
            ->once()
            ->andReturn(new ReceiptCrossReferenceSnapshot(
                auxiliaryId: '28050505',
                operationalCenter: 'A41',
                unit: '02',
                branch: '001',
                documentType: 'RFC',
                documentNumber: '00024089',
                exists: false,
            ));

        $guard = new ReceiptCrossReferenceGuard($dataSource, app('config'));

        $this->expectException(WorkerTaskProcessingException::class);
        $this->expectExceptionMessage('El recibo referencia un documento de cruce inexistente en Siesa.');

        $guard->assertExists(['document_id' => '002-FC-24089'], new stdClass());
    }

    public function test_it_allows_the_receipt_to_continue_in_warn_mode_when_the_cross_reference_does_not_exist(): void
    {
        config()->set('workerhub.receipts.cross_reference.enabled', true);
        config()->set('workerhub.receipts.cross_reference.mode', 'warn');

        $dataSource = Mockery::mock(ReceiptCrossReferenceDataSourceInterface::class);
        $dataSource->shouldReceive('fetch')
            ->once()
            ->andReturn(new ReceiptCrossReferenceSnapshot(
                auxiliaryId: '28050505',
                operationalCenter: 'A41',
                unit: '02',
                branch: '001',
                documentType: 'RFC',
                documentNumber: '00024089',
                exists: false,
            ));

        $guard = new ReceiptCrossReferenceGuard($dataSource, app('config'));

        $snapshot = $guard->assertExists(['document_id' => '002-FC-24089'], new stdClass());

        $this->assertFalse($snapshot->exists);
        $this->assertSame('RFC', $snapshot->documentType);
    }
}
