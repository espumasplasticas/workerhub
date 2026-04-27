<?php

namespace App\Services\Workers\Invoices;

use Epsalibrary\Legacy\Adapters\Invoices\LegacyInvoiceHeaderAdapter;
use Epsalibrary\Legacy\Adapters\Invoices\LegacyInvoiceLineAdapter;
use Epsalibrary\Legacy\Adapters\Invoices\LegacyInvoicePaymentAdapter;
use Epsalibrary\Siesa\Connectors\PrototipoFacturaCaja;
use Epsalibrary\Siesa\Connectors\PrototipoFacturaDetalle;
use Epsalibrary\Siesa\Connectors\PrototipoFacturaEncabezado;
use stdClass;

class InvoiceLineFactory
{
    /**
     * @param list<stdClass> $detailRows
     * @param list<stdClass> $paymentRows
     * @return list<string>
     */
    public function build(
        stdClass $headerRow,
        array $detailRows,
        array $paymentRows,
        float $cashPaymentAdjustment = 0.0,
        array $customerSyncLines = []
    ): array {
        $headerAdapter = new LegacyInvoiceHeaderAdapter($this->hydrate(new PrototipoFacturaEncabezado(), $headerRow));
        $lines = array_values($customerSyncLines);
        $lines[] = $headerAdapter->toLine();
        $paymentCondition = strtoupper(trim((string) ($headerRow->f461_id_cond_pago ?? '')));

        if ($this->shouldEmitPaymentLines($headerRow, $paymentCondition)) {
            $isFirstPaymentLine = true;

            foreach ($paymentRows as $paymentRow) {
                $paymentConnector = $this->hydrate(new PrototipoFacturaCaja(), $paymentRow);

                if ($isFirstPaymentLine && $cashPaymentAdjustment !== 0.0) {
                    $paymentConnector->F_VLR_MEDIO_PAGO = (float) ($paymentConnector->F_VLR_MEDIO_PAGO ?? 0) + $cashPaymentAdjustment;
                    $paymentConnector->F_VLR_MEDIO_PAGO_LOCAL = (float) ($paymentConnector->F_VLR_MEDIO_PAGO_LOCAL ?? 0) + $cashPaymentAdjustment;
                }

                $lines[] = (new LegacyInvoicePaymentAdapter($paymentConnector))->toLine();
                $isFirstPaymentLine = false;
            }
        } else {
            $lines[] = $headerAdapter->toAccountsReceivableLine();
        }

        foreach ($detailRows as $detailRow) {
            $connector = $this->hydrate(new PrototipoFacturaDetalle(), $detailRow);
            $adapter = new LegacyInvoiceLineAdapter($connector);
            $taxIncluded = (int) ($detailRow->FD_IndicadorImpuestoIncluido ?? 0) === 1;
            $lines[] = $adapter->toPriceLine(0, $taxIncluded);

            if ((float) ($detailRow->PorcentajeDescuento ?? 0) > 0 || (float) ($detailRow->FD_DescuentoValor ?? 0) > 0) {
                $lines[] = $adapter->toDiscountLine();
            }

            if ((float) ($detailRow->FD_TasaDescuento2 ?? 0) > 0) {
                $lines[] = $adapter->toManualDiscountLine(2, (float) $detailRow->FD_TasaDescuento2, 0);
            } elseif ((float) ($detailRow->FD_DescuentoValor2 ?? 0) > 0) {
                $lines[] = $adapter->toManualDiscountLine(2, 0, (float) $detailRow->FD_DescuentoValor2);
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed>|stdClass $attributes
     */
    private function hydrate(object $connector, array|stdClass $attributes): object
    {
        foreach ((array) $attributes as $field => $value) {
            if (is_string($field) && property_exists($connector, $field)) {
                $connector->{$field} = $value;
            }
        }

        return $connector;
    }

    private function shouldEmitPaymentLines(stdClass $headerRow, string $paymentCondition): bool
    {
        if (trim((string) ($headerRow->FE_FormaDePago ?? '')) === '0') {
            return true;
        }

        return in_array($paymentCondition, ['CONT', '1', '001', '000', ''], true);
    }
}
