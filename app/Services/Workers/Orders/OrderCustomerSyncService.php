<?php

namespace App\Services\Workers\Orders;

use App\Contracts\ReceiptCustomerSyncDataSourceInterface;
use App\Services\Workers\Receipts\ReceiptCustomerSyncLineFactory;
use Illuminate\Contracts\Config\Repository;
use stdClass;

class OrderCustomerSyncService
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
    public function sync(array $payload, stdClass $header): array
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

        $enterpriseOperationalCenter = trim((string) ($header->f430_id_co ?? ''));
        $thirdPartyId = trim((string) ($header->f430_id_tercero_fact ?? ''));
        $sourceBranch = trim((string) (
            $header->PE_CodigoSucursal
            ?? $header->order_source_branch
            ?? ($payload['client_branch'] ?? '')
        ));

        $snapshot = $this->dataSource->fetchThirdParty(
            $payload,
            $thirdPartyId,
            $sourceBranch,
            $enterpriseOperationalCenter
        );

        if (!$snapshot->shouldSync) {
            return [
                'status' => 'skipped',
                'line_count' => 0,
                'prepared_parties' => 0,
                'synced_parties' => 0,
                'parties' => [[
                    'role' => 'order_customer',
                    'status' => 'skipped',
                    'line_count' => 0,
                    'snapshot' => $snapshot->toArray(),
                ]],
                'lines' => [],
            ];
        }

        $lines = $this->lineFactory->build($snapshot);

        return [
            'status' => 'prepared',
            'line_count' => count($lines),
            'prepared_parties' => 1,
            'synced_parties' => 1,
            'parties' => [[
                'role' => 'order_customer',
                'status' => 'prepared',
                'line_count' => count($lines),
                'snapshot' => $snapshot->toArray(),
            ]],
            'lines' => $lines,
        ];
    }

    private function isEnabled(): bool
    {
        return (bool) $this->config->get('workerhub.orders.customer_sync.enabled', true);
    }
}
