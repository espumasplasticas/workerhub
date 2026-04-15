<?php

namespace App\Services\Workers;

use App\Data\SiesaWebServiceLogRecord;
use Epsalibrary\Results\ImportResult;

final class SiesaImportAuditResult
{
    public function __construct(
        public readonly SiesaWebServiceLogRecord $log,
        public readonly ImportResult $result,
    ) {
    }
}
