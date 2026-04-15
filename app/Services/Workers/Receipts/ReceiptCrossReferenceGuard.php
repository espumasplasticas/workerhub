<?php

namespace App\Services\Workers\Receipts;

use App\Contracts\ReceiptCrossReferenceDataSourceInterface;
use App\Data\Receipts\ReceiptCrossReferenceSnapshot;
use App\Exceptions\WorkerTaskProcessingException;
use Illuminate\Contracts\Config\Repository;
use stdClass;

class ReceiptCrossReferenceGuard
{
    public function __construct(
        private readonly ReceiptCrossReferenceDataSourceInterface $dataSource,
        private readonly Repository $config
    ) {
    }

    public function assertExists(array $payload, stdClass $receiptHeader): ReceiptCrossReferenceSnapshot
    {
        if (!$this->isEnabled()) {
            return new ReceiptCrossReferenceSnapshot(
                auxiliaryId: trim((string) $this->config->get('workerhub.receipts.cross_reference.auxiliary_id', '28050505')),
                operationalCenter: trim((string) ($receiptHeader->F350_ID_CO ?? '')),
                unit: trim((string) $this->config->get('workerhub.receipts.cross_reference.unit', '02')),
                branch: str_pad(trim((string) ($receiptHeader->F353_ID_SUCURSAL_DOCTO_CRUCE ?? '')), 3, '0', STR_PAD_LEFT),
                documentType: trim((string) ($receiptHeader->F350_ID_TIPO_DOCTO ?? '')),
                documentNumber: trim((string) ($receiptHeader->F350_CONSEC_DOCTO ?? '')),
                exists: true,
            );
        }

        $snapshot = $this->dataSource->fetch($payload, $receiptHeader);

        if (!$snapshot->exists && $this->shouldBlockOnMissingReference()) {
            throw new WorkerTaskProcessingException(
                'El recibo referencia un documento de cruce inexistente en Siesa.',
                [
                    'payload' => $payload,
                    'cross_reference' => $snapshot->toArray(),
                ]
            );
        }

        return $snapshot;
    }

    private function isEnabled(): bool
    {
        return (bool) $this->config->get('workerhub.receipts.cross_reference.enabled', true);
    }

    private function shouldBlockOnMissingReference(): bool
    {
        return (string) $this->config->get('workerhub.receipts.cross_reference.mode', 'strict') !== 'warn';
    }
}
