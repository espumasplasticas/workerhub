<?php

namespace App\Services\Workers\Receipts;

use App\Contracts\ReceiptCustomerSyncDataSourceInterface;
use Illuminate\Contracts\Config\Repository;
use stdClass;

class ReceiptCustomerSyncService
{
    public function __construct(
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
                'prepared_parties' => 0,
                'synced_parties' => 0,
                'parties' => [],
                'lines' => [],
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
        $preparedCount = 0;
        $parties = [];
        $lines = [];

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

            $preparedLines = $this->lineFactory->build($snapshot);
            $lines = array_merge($lines, $preparedLines);
            $lineCount += count($preparedLines);
            $preparedCount++;
            $parties[] = [
                'role' => $role,
                'status' => 'prepared',
                'line_count' => count($preparedLines),
                'snapshot' => $snapshot->toArray(),
            ];
        }

        return [
            'status' => $preparedCount > 0 ? 'prepared' : 'skipped',
            'line_count' => $lineCount,
            'prepared_parties' => $preparedCount,
            'synced_parties' => $preparedCount,
            'parties' => $parties,
            'lines' => $lines,
        ];
    }

    private function isEnabled(): bool
    {
        return (bool) $this->config->get('workerhub.receipts.customer_sync.enabled', true);
    }
}
