<?php

namespace App\Data\Invoices;

final class InvoiceSiesaStateSnapshot
{
    public function __construct(
        public readonly string $operationalCenter,
        public readonly string $documentType,
        public readonly string $documentNumber,
        public readonly bool $exists,
        public readonly ?string $enterpriseOperationalCenter = null,
        public readonly ?string $enterpriseDocumentType = null,
        public readonly ?string $enterpriseDocumentNumber = null,
        public readonly ?int $rowId = null,
        public readonly ?float $netTotal = null,
        public readonly ?int $stateIndicator = null,
    ) {
    }

    /**
     * @return array<string, scalar|bool|null>
     */
    public function toArray(): array
    {
        return [
            'operational_center' => $this->operationalCenter,
            'document_type' => $this->documentType,
            'document_number' => $this->documentNumber,
            'exists' => $this->exists,
            'enterprise_operational_center' => $this->enterpriseOperationalCenter,
            'enterprise_document_type' => $this->enterpriseDocumentType,
            'enterprise_document_number' => $this->enterpriseDocumentNumber,
            'row_id' => $this->rowId,
            'net_total' => $this->netTotal,
            'state_indicator' => $this->stateIndicator,
        ];
    }
}
