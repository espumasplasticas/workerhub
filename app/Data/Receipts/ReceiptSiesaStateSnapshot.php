<?php

namespace App\Data\Receipts;

final class ReceiptSiesaStateSnapshot
{
    public function __construct(
        public readonly string $operationalCenter,
        public readonly string $documentType,
        public readonly string $documentNumber,
        public readonly bool $exists,
        public readonly ?string $accountingOperationalCenter = null,
        public readonly ?string $accountingDocumentType = null,
        public readonly ?string $accountingDocumentNumber = null,
        public readonly ?float $creditTotal = null,
        public readonly ?float $debitTotal = null,
        public readonly ?int $stateIndicator = null,
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
     * @return array<string, scalar|bool|null>
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference(),
            'operational_center' => $this->operationalCenter,
            'document_type' => $this->documentType,
            'document_number' => $this->documentNumber,
            'exists' => $this->exists,
            'accounting_operational_center' => $this->accountingOperationalCenter,
            'accounting_document_type' => $this->accountingDocumentType,
            'accounting_document_number' => $this->accountingDocumentNumber,
            'credit_total' => $this->creditTotal,
            'debit_total' => $this->debitTotal,
            'state_indicator' => $this->stateIndicator,
        ];
    }
}
