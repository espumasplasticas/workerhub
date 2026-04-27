<?php

namespace Tests\Unit;

use App\Services\Workers\DocumentImportAttemptControlService;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Mockery;
use stdClass;
use Tests\TestCase;

class DocumentImportAttemptControlServiceTest extends TestCase
{
    public function test_it_registers_order_and_customer_attempts_in_the_control_table(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('affectingStatement')
            ->once()
            ->with(
                Mockery::on(static fn (string $sql): bool => str_contains($sql, 'MERGE pos.control_importacion_documentos AS target')),
                Mockery::on(static fn (array $bindings): bool => $bindings[0] === '002'
                    && $bindings[1] === 'CLI'
                    && $bindings[2] === '900123-00')
            )
            ->andReturn(1);
        $connection->shouldReceive('affectingStatement')
            ->once()
            ->with(
                Mockery::on(static fn (string $sql): bool => str_contains($sql, 'MERGE pos.control_importacion_documentos AS target')),
                Mockery::on(static fn (array $bindings): bool => $bindings[0] === '002'
                    && $bindings[1] === 'FC'
                    && $bindings[2] === '24139')
            )
            ->andReturn(1);

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->twice()->with('source_test')->andReturn($connection);

        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')->with('workerhub.orders.source_connections.test', 'source_sqlsrv')->andReturn('source_test');
        $config->shouldReceive('get')->with('workerhub.import_attempt_control.customer_document_type', 'CLI')->andReturn('CLI');
        $config->shouldReceive('get')->with('workerhub.import_attempt_control.table', 'pos.control_importacion_documentos')->andReturn('pos.control_importacion_documentos');

        $service = new DocumentImportAttemptControlService($database, $config);

        $payload = [
            'db_connection' => 'test',
            'operational_center' => '002',
            'document_type' => 'FC',
            'document_number' => '24139',
        ];

        $customerSync = [
            'parties' => [
                [
                    'status' => 'prepared',
                    'snapshot' => [
                        'third_party_id' => '900123',
                        'source_branch' => '00',
                    ],
                ],
                [
                    'status' => 'prepared',
                    'snapshot' => [
                        'third_party_id' => '900123',
                        'source_branch' => '00',
                    ],
                ],
            ],
        ];

        $service->registerPreparedOrderCustomerAttempts($payload, $customerSync);
        $service->registerOrderMigrationAttempt($payload);
    }

    public function test_it_registers_receipt_customer_attempts_with_receipt_source_connection(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('affectingStatement')
            ->once()
            ->with(
                Mockery::on(static fn (string $sql): bool => str_contains($sql, 'MERGE pos.control_importacion_documentos AS target')),
                Mockery::on(static fn (array $bindings): bool => $bindings[0] === '001'
                    && $bindings[1] === 'CLI'
                    && $bindings[2] === '456789-06')
            )
            ->andReturn(1);
        $connection->shouldReceive('affectingStatement')
            ->once()
            ->with(
                Mockery::on(static fn (string $sql): bool => str_contains($sql, 'MERGE pos.control_importacion_documentos AS target')),
                Mockery::on(static fn (array $bindings): bool => $bindings[0] === '001'
                    && $bindings[1] === 'RX'
                    && $bindings[2] === '1001')
            )
            ->andReturn(1);

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->twice()->with('source_test')->andReturn($connection);

        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')->with('workerhub.receipts.source_connections.test', 'source_sqlsrv')->andReturn('source_test');
        $config->shouldReceive('get')->with('workerhub.import_attempt_control.customer_document_type', 'CLI')->andReturn('CLI');
        $config->shouldReceive('get')->with('workerhub.import_attempt_control.table', 'pos.control_importacion_documentos')->andReturn('pos.control_importacion_documentos');

        $service = new DocumentImportAttemptControlService($database, $config);

        $payload = [
            'db_connection' => 'test',
            'operational_center' => '001',
            'document_type' => 'RX',
            'document_number' => '1001',
        ];

        $customerSync = [
            'parties' => [
                [
                    'status' => 'prepared',
                    'snapshot' => [
                        'third_party_id' => '456789',
                        'source_branch' => '06',
                    ],
                ],
            ],
        ];

        $service->registerPreparedReceiptCustomerAttempts($payload, $customerSync);
        $service->registerReceiptMigrationAttempt($payload);
    }

    public function test_it_registers_invoice_attempts_with_the_legacy_retry_cycle_and_invoice_customer_tracking(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('affectingStatement')
            ->once()
            ->with(
                Mockery::on(static fn (string $sql): bool => str_contains($sql, 'MERGE pos.control_importacion_documentos AS target')),
                Mockery::on(static fn (array $bindings): bool => $bindings[0] === '002'
                    && $bindings[1] === 'CLI'
                    && $bindings[2] === '900555-01')
            )
            ->andReturn(1);
        $connection->shouldReceive('selectOne')
            ->once()
            ->with(
                Mockery::on(static fn (string $sql): bool => str_contains($sql, 'OUTPUT INSERTED.DC_intentos AS current_attempt')),
                Mockery::on(static fn (array $bindings): bool => $bindings[0] === '002'
                    && $bindings[1] === 'F4'
                    && $bindings[2] === '24787'
                    && $bindings[3] === 28)
            )
            ->andReturn((object) ['current_attempt' => 7]);

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->twice()->with('source_test')->andReturn($connection);

        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')->with('workerhub.invoices.source_connections.test', 'source_sqlsrv')->andReturn('source_test');
        $config->shouldReceive('get')->with('workerhub.import_attempt_control.customer_document_type', 'CLI')->andReturn('CLI');
        $config->shouldReceive('get')->with('workerhub.import_attempt_control.table', 'pos.control_importacion_documentos')->andReturn('pos.control_importacion_documentos');
        $config->shouldReceive('get')->with('workerhub.invoices.import_attempts.cycle_limit', 28)->andReturn(28);

        $service = new DocumentImportAttemptControlService($database, $config);

        $payload = [
            'db_connection' => 'test',
            'operational_center' => '002',
            'document_type' => 'F4',
            'document_number' => '24787',
        ];

        $customerSync = [
            'parties' => [
                [
                    'status' => 'prepared',
                    'snapshot' => [
                        'third_party_id' => '900555',
                        'source_branch' => '01',
                    ],
                ],
            ],
        ];

        $service->registerPreparedInvoiceCustomerAttempts($payload, $customerSync);

        $this->assertSame(7, $service->registerInvoiceMigrationAttemptAndReturnAttemptNumber($payload));
    }
}
