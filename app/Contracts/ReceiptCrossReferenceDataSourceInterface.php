<?php

namespace App\Contracts;

use App\Data\Receipts\ReceiptCrossReferenceSnapshot;
use stdClass;

interface ReceiptCrossReferenceDataSourceInterface
{
    public function fetch(array $payload, stdClass $receiptHeader): ReceiptCrossReferenceSnapshot;
}
