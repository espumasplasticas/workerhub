<?php

namespace Tests\Unit\Workers\Receipts;

use App\Contracts\ReceiptPreMigrationDataSourceInterface;
use App\Data\Receipts\ReceiptPreMigrationSnapshot;
use App\Exceptions\WorkerTaskProcessingException;
use App\Services\Workers\Receipts\ReceiptPreMigrationGuard;
use Illuminate\Contracts\Config\Repository;
use Mockery;
use Tests\TestCase;

class ReceiptPreMigrationGuardTest extends TestCase
{
    public function test_it_allows_migration_when_the_legalized_value_covers_the_receipt_total(): void
    {
        $provider = Mockery::mock(ReceiptPreMigrationDataSourceInterface::class);
        $provider->shouldReceive('fetch')
            ->once()
            ->andReturn(new ReceiptPreMigrationSnapshot(
                operationalCenter: '002',
                documentType: 'FC',
                documentNumber: '24088',
                totalAmount: 326000,
                legalizedAmount: 326000,
                isCancelled: false,
                isCancellationRequested: false,
                isWompiExpiredWithoutPayment: false,
            ));

        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')
            ->once()
            ->with('workerhub.receipts.pre_migration.enabled', true)
            ->andReturn(true);

        $guard = new ReceiptPreMigrationGuard($provider, $config);

        $snapshot = $guard->assertCanMigrate(['document_id' => '002-FC-24088']);

        $this->assertSame('002-FC-24088', $snapshot->reference());
    }

    public function test_it_allows_special_receipts_even_without_full_legalization(): void
    {
        $provider = Mockery::mock(ReceiptPreMigrationDataSourceInterface::class);
        $provider->shouldReceive('fetch')
            ->once()
            ->andReturn(new ReceiptPreMigrationSnapshot(
                operationalCenter: '002',
                documentType: 'RCP',
                documentNumber: '10',
                totalAmount: 50000,
                legalizedAmount: 0,
                isCancelled: false,
                isCancellationRequested: false,
                isWompiExpiredWithoutPayment: false,
            ));

        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')
            ->once()
            ->with('workerhub.receipts.pre_migration.enabled', true)
            ->andReturn(true);

        $guard = new ReceiptPreMigrationGuard($provider, $config);

        $snapshot = $guard->assertCanMigrate(['document_id' => '002-RCP-10']);

        $this->assertSame('RCP', $snapshot->documentType);
    }

    public function test_it_allows_legacy_verified_receipts_even_without_full_legalization(): void
    {
        $provider = Mockery::mock(ReceiptPreMigrationDataSourceInterface::class);
        $provider->shouldReceive('fetch')
            ->once()
            ->andReturn(new ReceiptPreMigrationSnapshot(
                operationalCenter: '001',
                documentType: 'A79',
                documentNumber: '76',
                totalAmount: 1017450,
                legalizedAmount: 0,
                isCancelled: false,
                isCancellationRequested: false,
                isWompiExpiredWithoutPayment: false,
                isLegacyMigrated: true,
                isLegacyExportVerified: true,
            ));

        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')
            ->once()
            ->with('workerhub.receipts.pre_migration.enabled', true)
            ->andReturn(true);

        $guard = new ReceiptPreMigrationGuard($provider, $config);

        $snapshot = $guard->assertCanMigrate(['document_id' => '001-A79-76']);

        $this->assertSame('001-A79-76', $snapshot->reference());
        $this->assertTrue($snapshot->isLegacyExportVerified);
    }

    public function test_it_rejects_receipts_that_do_not_meet_any_precondition(): void
    {
        $provider = Mockery::mock(ReceiptPreMigrationDataSourceInterface::class);
        $provider->shouldReceive('fetch')
            ->once()
            ->andReturn(new ReceiptPreMigrationSnapshot(
                operationalCenter: '002',
                documentType: 'FC',
                documentNumber: '24088',
                totalAmount: 326000,
                legalizedAmount: 0,
                isCancelled: false,
                isCancellationRequested: false,
                isWompiExpiredWithoutPayment: false,
            ));

        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')
            ->once()
            ->with('workerhub.receipts.pre_migration.enabled', true)
            ->andReturn(true);

        $guard = new ReceiptPreMigrationGuard($provider, $config);

        $this->expectException(WorkerTaskProcessingException::class);
        $this->expectExceptionMessage('El recibo aun no cumple las precondiciones para migrarse a Siesa.');

        $guard->assertCanMigrate(['document_id' => '002-FC-24088']);
    }
}
