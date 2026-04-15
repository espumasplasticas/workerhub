<?php

namespace App\Contracts;

use App\Data\Receipts\ReceiptCustomerSyncSnapshot;

interface ReceiptCustomerSyncDataSourceInterface
{
    public function fetch(array $payload, string $enterpriseOperationalCenter): ReceiptCustomerSyncSnapshot;
}
