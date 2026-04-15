<?php

namespace App\Data\Receipts;

final class ReceiptCrossReferenceSnapshot
{
    public function __construct(
        public readonly string $auxiliaryId,
        public readonly string $operationalCenter,
        public readonly string $unit,
        public readonly string $branch,
        public readonly string $documentType,
        public readonly string $documentNumber,
        public readonly bool $exists,
    ) {
    }

    /**
     * @return array<string, scalar|bool>
     */
    public function toArray(): array
    {
        return [
            'auxiliary_id' => $this->auxiliaryId,
            'operational_center' => $this->operationalCenter,
            'unit' => $this->unit,
            'branch' => $this->branch,
            'document_type' => $this->documentType,
            'document_number' => $this->documentNumber,
            'exists' => $this->exists,
        ];
    }
}
