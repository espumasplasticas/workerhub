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
                'parties' => [],
            ];
        }

        $enterpriseOperationalCenter = trim((string) ($receiptHeader->F350_ID_CO ?? ''));
        $snapshots = [
            'receipt_customer' => $this->dataSource->fetch($payload, $enterpriseOperationalCenter),
        ];

        $otherIncomeThirdParty = trim((string) ($receiptHeader->F351_ID_TERCERO_OTRO_ING ?? ''));
        $receiptCustomer = trim((string) ($receiptHeader->F350_ID_TERCERO ?? ''));

        if ($otherIncomeThirdParty !== '' && $otherIncomeThirdParty !== $receiptCustomer) {
            $snapshots['other_income_third_party'] = $this->dataSource->fetchThirdParty(
                $payload,
                $otherIncomeThirdParty,
                trim((string) ($receiptHeader->F351_ID_SUCURSAL_OTRO_ING ?? '')),
                $enterpriseOperationalCenter
            );
        }

        $lineCount = 0;
        $parties = [];
        $messages = [];
        $syncedCount = 0;

        foreach ($snapshots as $role => $snapshot) {
            if (!$snapshot->shouldSync) {
                $parties[] = [
                    'role' => $role,
                    'status' => 'skipped',
                    'line_count' => 0,
                    'snapshot' => $snapshot->toArray(),
                ];

                continue;
            }

            $lines = $this->lineFactory->build($snapshot);
            $result = $this->importManager->import($lines);

            if (!$result->success) {
                throw new WorkerTaskProcessingException(
                    sprintf('Fallo importando tercero/cliente previo al recibo (%s).', $role),
                    [
                        'errors' => $result->errors,
                        'payload' => $payload,
                        'customer_sync_role' => $role,
                        'customer_sync' => $snapshot->toArray(),
                        'xml_payload' => $result->payload,
                    ]
                );
            }

            $lineCount += count($lines);
            $syncedCount++;
            $messages[] = $result->message;
            $parties[] = [
                'role' => $role,
                'status' => 'synced',
                'line_count' => count($lines),
                'message' => $result->message,
                'errors' => $result->errors,
                'snapshot' => $snapshot->toArray(),
                'import_payload' => $result->payload,
            ];
        }

        return [
            'status' => $syncedCount > 0 ? 'synced' : 'skipped',
            'line_count' => $lineCount,
            'synced_parties' => $syncedCount,
            'parties' => $parties,
            'messages' => $messages,
        ];
    }

    private function isEnabled(): bool
    {
        return (bool) $this->config->get('workerhub.receipts.customer_sync.enabled', true);
    }
}
