<?php

namespace App\Data\Receipts;

final class ReceiptPreMigrationSnapshot
{
    public function __construct(
        public readonly string $operationalCenter,
        public readonly string $documentType,
        public readonly string $documentNumber,
        public readonly float $totalAmount,
        public readonly float $legalizedAmount,
        public readonly bool $isCancelled,
        public readonly bool $isCancellationRequested,
        public readonly bool $isWompiExpiredWithoutPayment,
        public readonly int $servicreditoPaymentCount = 0,
        public readonly bool $isLegacyMigrated = false,
        public readonly bool $isLegacyExportVerified = false,
    ) {
    }

    public function reference(): string
    {
        return implode('-', array_filter([
            trim($this->operationalCenter),
            trim($this->documentType),
            trim($this->documentNumber),
        ], static fn (string $value): bool => $value !== ''));
    }

    /**
     * @return array<string, scalar>
     */
    public function toArray(): array
    {
        return [
            'operational_center' => $this->operationalCenter,
            'document_type' => $this->documentType,
            'document_number' => $this->documentNumber,
            'reference' => $this->reference(),
            'total_amount' => $this->totalAmount,
            'legalized_amount' => $this->legalizedAmount,
            'is_cancelled' => $this->isCancelled,
            'is_cancellation_requested' => $this->isCancellationRequested,
            'is_wompi_expired_without_payment' => $this->isWompiExpiredWithoutPayment,
            'servicredito_payment_count' => $this->servicreditoPaymentCount,
            'is_legacy_migrated' => $this->isLegacyMigrated,
            'is_legacy_export_verified' => $this->isLegacyExportVerified,
        ];
    }
}
