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
     * Devuelve la secuencia completa de ajustes de caja que debe probarse
     * dentro de un mismo ciclo de migracion antes de considerar el intento
     * legacy como fallido.
     *
     * @param list<stdClass> $detailRows
     * @param list<stdClass> $paymentRows
     * @return list<float>
     */
    public function resolveCashPaymentAdjustmentSequence(
        stdClass $invoiceHeader,
        array $detailRows,
        array $paymentRows
    ): array {
        if (!$this->isCashInvoice($invoiceHeader)) {
            return [0.0];
        }

        $candidates = [];
        $legacyNetTotal = $this->legacyAmountCalculator->calculateLegacyNetTotalFromDetails($detailRows);
        $paymentTotal = $this->legacyAmountCalculator->calculateCollectedPaymentTotal($paymentRows);

        $candidates[] = $legacyNetTotal - $paymentTotal;
        $candidates[] = $this->legacyAmountCalculator->calculateRoundedRetryAdjustment($detailRows);

        for ($attemptNumber = 7; $attemptNumber <= 27; $attemptNumber++) {
            $candidates[] = $this->resolveLegacyAlternatingAdjustmentForRetryAttempt($attemptNumber);
        }

        return $this->deduplicateAdjustments($candidates);
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

    /**
     * @param list<float> $candidates
     * @return list<float>
     */
    private function deduplicateAdjustments(array $candidates): array
    {
        $resolved = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            $normalizedCandidate = abs($candidate) < 0.0001 ? 0.0 : round($candidate, 4);
            $key = number_format($normalizedCandidate, 4, '.', '');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $resolved[] = $normalizedCandidate;
        }

        return $resolved === [] ? [0.0] : $resolved;
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
