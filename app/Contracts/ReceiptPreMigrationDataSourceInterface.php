<?php

namespace App\Contracts;

use App\Data\Receipts\ReceiptPreMigrationSnapshot;

interface ReceiptPreMigrationDataSourceInterface
{
    public function fetch(array $payload): ReceiptPreMigrationSnapshot;
}