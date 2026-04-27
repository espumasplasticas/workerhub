<?php

namespace App\Services\Workers\Invoices;

use stdClass;

class InvoicePaymentAdjustmentResolver
{
    public function __construct(
        private readonly InvoiceLegacyAmountCalculator $legacyAmountCalculator
    ) {
    }

    /**
     * @param list<stdClass> $detailRows
     * @param list<stdClass> $paymentRows
     */
    public function resolveCashPaymentAdjustment(
        stdClass $invoiceHeader,
        array $detailRows,
        array $paymentRows,
        int $attemptNumber
    ): float {
        if (!$this->isCashInvoice($invoiceHeader)) {
            return 0.0;
        }

        if ($attemptNumber < 3) {
            $legacyNetTotal = $this->legacyAmountCalculator->calculateLegacyNetTotalFromDetails($detailRows);
            $paymentTotal = $this->legacyAmountCalculator->calculateCollectedPaymentTotal($paymentRows);

            return $legacyNetTotal - $paymentTotal;
        }

        if ($attemptNumber <= 6) {
            return $this->legacyAmountCalculator->calculateRoundedRetryAdjustment($detailRows);
        }

        return $this->resolveLegacyAlternatingAdjustmentForRetryAttempt($attemptNumber);
    }

    private function isCashInvoice(stdClass $invoiceHeader): bool
    {
        return trim((string) ($invoiceHeader->FE_FormaDePago ?? '')) === '0';
    }

    private function resolveLegacyAlternatingAdjustmentForRetryAttempt(int $attemptNumber): float
    {
        if ($attemptNumber < 7 || $attemptNumber > 27) {
            return 0.0;
        }

        if ($attemptNumber === 7) {
            return 0.0;
        }

        $offset = $attemptNumber - 7;
        $absoluteAdjustment = (int) ceil($offset / 2);

        return $offset % 2 === 1
            ? (float) $absoluteAdjustment
            : (float) (-1 * $absoluteAdjustment);
    }
}
