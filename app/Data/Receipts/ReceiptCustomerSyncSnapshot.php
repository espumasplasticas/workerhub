<?php

namespace App\Data\Receipts;

final class ReceiptCustomerSyncSnapshot
{
    /**
     * @param array<string, mixed>|null $thirdPartyPrototype
     * @param array<string, mixed>|null $branchPrototype
     */
    public function __construct(
        public readonly string $enterpriseOperationalCenter,
        public readonly string $thirdPartyId,
        public readonly string $sourceBranch,
        public readonly string $customerClassId,
        public readonly bool $allowsSelection,
        public readonly bool $canMigrate,
        public readonly bool $shouldSync,
        public readonly ?string $skipReason = null,
        public readonly ?array $thirdPartyPrototype = null,
        public readonly ?array $branchPrototype = null,
    ) {
    }

    /**
     * @return array<string, scalar|bool|null>
     */
    public function toArray(): array
    {
        return [
            'enterprise_operational_center' => $this->enterpriseOperationalCenter,
            'third_party_id' => $this->thirdPartyId,
            'source_branch' => $this->sourceBranch,
            'customer_class_id' => $this->customerClassId,
            'allows_selection' => $this->allowsSelection,
            'can_migrate' => $this->canMigrate,
            'should_sync' => $this->shouldSync,
            'skip_reason' => $this->skipReason,
            'third_party_prototype_available' => $this->thirdPartyPrototype !== null,
            'branch_prototype_available' => $this->branchPrototype !== null,
        ];
    }
}
