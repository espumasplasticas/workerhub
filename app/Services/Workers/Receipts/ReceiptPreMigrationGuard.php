<?php

namespace App\Services\Workers\Receipts;

use App\Contracts\ReceiptPreMigrationDataSourceInterface;
use App\Data\Receipts\ReceiptPreMigrationSnapshot;
use App\Exceptions\WorkerTaskProcessingException;
use Illuminate\Contracts\Config\Repository;

class ReceiptPreMigrationGuard
{
    public function __construct(
        private readonly ReceiptPreMigrationDataSourceInterface $dataSource,
        private readonly Repository $config
    ) {
    }

    public function assertCanMigrate(array $payload): ReceiptPreMigrationSnapshot
    {
        $snapshot = $this->dataSource->fetch($payload);

        if (!$this->isEnabled()) {
            return $snapshot;
        }

        if ($this->matchesAnyAllowedCondition($snapshot)) {
            return $snapshot;
        }

        throw new WorkerTaskProcessingException(
            'El recibo aun no cumple las precondiciones para migrarse a Siesa.',
            [
                'receipt' => $snapshot->toArray(),
                'expected_conditions' => [
                    'legalized_amount_gte_total_amount',
                    'cancelled',
                    'cancellation_requested',
                    'wompi_expired_without_payment',
                    'document_type_A06',
                    'document_type_RCP',
                ],
            ]
        );
    }

    private function isEnabled(): bool
    {
        return (bool) $this->config->get('workerhub.receipts.pre_migration.enabled', true);
    }

    private function matchesAnyAllowedCondition(ReceiptPreMigrationSnapshot $snapshot): bool
    {
        return $snapshot->legalizedAmount >= $snapshot->totalAmount
            || $snapshot->isCancelled
            || $snapshot->isCancellationRequested
            || $snapshot->isWompiExpiredWithoutPayment
            || in_array($snapshot->documentType, ['A06', 'RCP'], true);
    }
}