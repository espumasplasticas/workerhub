<?php

namespace App\Services\Workers\Invoices;

use App\Contracts\ReceiptCustomerSyncDataSourceInterface;
use App\Services\Workers\Receipts\ReceiptCustomerSyncLineFactory;
use Illuminate\Contracts\Config\Repository;
use stdClass;

class InvoiceCustomerSyncService
{
    public function __construct(
        private readonly ReceiptCustomerSyncDataSourceInterface $dataSource,
        private readonly ReceiptCustomerSyncLineFactory $lineFactory,
        private readonly Repository $config
    ) {
    }

    /**
     * Prepara las lineas del tercero/sucursal de la factura antes de la importacion.
     *
     * @return array<string, mixed>
     */
    public function sync(array $payload, stdClass $invoiceHeader): array
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

        $enterpriseOperationalCenter = trim((string) ($invoiceHeader->F350_ID_CO ?? ''));
        $thirdPartyId = trim((string) ($invoiceHeader->F350_ID_TERCERO ?? ''));
        $sourceBranch = trim((string) (
            $invoiceHeader->f461_id_sucursal_fact
            ?? $payload['client_branch']
            ?? '001'
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
                    'role' => 'invoice_customer',
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
                'role' => 'invoice_customer',
                'status' => 'prepared',
                'line_count' => count($lines),
                'snapshot' => $snapshot->toArray(),
            ]],
            'lines' => $lines,
        ];
    }

    private function isEnabled(): bool
    {
        return (bool) $this->config->get('workerhub.invoices.customer_sync.enabled', true);
    }
}
