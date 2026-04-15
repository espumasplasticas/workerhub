<?php

namespace App\Services\Workers\Receipts;

use App\Contracts\ReceiptCustomerSyncDataSourceInterface;
use App\Exceptions\WorkerTaskProcessingException;
use Epsalibrary\Contracts\ImportManagerInterface;
use Illuminate\Contracts\Config\Repository;
use stdClass;

class ReceiptCustomerSyncService
{
    public function __construct(
        private readonly ImportManagerInterface $importManager,
        private readonly ReceiptCustomerSyncDataSourceInterface $dataSource,
        private readonly ReceiptCustomerSyncLineFactory $lineFactory,
        private readonly Repository $config
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function sync(array $payload, stdClass $receiptHeader): array
    {
        if (!$this->isEnabled()) {
            return [
                'status' => 'disabled',
                'line_count' => 0,
                'snapshot' => null,
            ];
        }

        $snapshot = $this->dataSource->fetch(
            $payload,
            trim((string) ($receiptHeader->F350_ID_CO ?? ''))
        );

        if (!$snapshot->shouldSync) {
            return [
                'status' => 'skipped',
                'line_count' => 0,
                'snapshot' => $snapshot->toArray(),
            ];
        }

        $lines = $this->lineFactory->build($snapshot);
        $result = $this->importManager->import($lines);

        if (!$result->success) {
            throw new WorkerTaskProcessingException(
                'Fallo importando tercero/cliente previo al recibo.',
                [
                    'errors' => $result->errors,
                    'payload' => $payload,
                    'customer_sync' => $snapshot->toArray(),
                    'xml_payload' => $result->payload,
                ]
            );
        }

        return [
            'status' => 'synced',
            'line_count' => count($lines),
            'message' => $result->message,
            'errors' => $result->errors,
            'snapshot' => $snapshot->toArray(),
            'import_payload' => $result->payload,
        ];
    }

    private function isEnabled(): bool
    {
        return (bool) $this->config->get('workerhub.receipts.customer_sync.enabled', true);
    }
}
