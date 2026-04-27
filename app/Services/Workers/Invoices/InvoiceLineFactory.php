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
            // Prepare effective payments array. If a cash adjustment is requested,
            // we append an explicit adjustment payment row instead of mutating
            // the existing first payment. This makes the change deterministic
            // and auditable in the generated lines.
            $effectivePayments = array_values($paymentRows);

            if ($cashPaymentAdjustment !== 0.0) {
                $adjustment = new \stdClass();
                // Ensure document identification fields exist on the adjustment so
                // the connector hydrator and adapter can build a valid line.
                $adjustment->F350_ID_CO = $headerRow->F350_ID_CO ?? ($headerRow->f350_id_co ?? null);
                $adjustment->F350_ID_TIPO_DOCTO = $headerRow->F350_ID_TIPO_DOCTO ?? ($headerRow->f350_id_tipo_docto ?? null);
                $adjustment->F350_CONSEC_DOCTO = $headerRow->F350_CONSEC_DOCTO ?? ($headerRow->f350_consec_docto ?? null);

                // Use the first payment method if available, otherwise a fallback code '999'.
                $firstPaymentMethod = $paymentRows[0]->F358_ID_MEDIOS_PAGO ?? $paymentRows[0]->f358_id_medios_pago ?? '999';
                $adjustment->F358_ID_MEDIOS_PAGO = $firstPaymentMethod;
                $adjustment->F358_ID_MONEDA = $paymentRows[0]->F358_ID_MONEDA ?? $paymentRows[0]->f358_id_moneda ?? null;

                $adjustment->F_VLR_MEDIO_PAGO = $cashPaymentAdjustment;
                $adjustment->F_VLR_MEDIO_PAGO_LOCAL = $cashPaymentAdjustment;

                $effectivePayments[] = $adjustment;
            }

            foreach ($effectivePayments as $paymentRow) {
                $paymentConnector = $this->hydrate(new PrototipoFacturaCaja(), $paymentRow);
                $lines[] = (new LegacyInvoicePaymentAdapter($paymentConnector))->toLine();
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
