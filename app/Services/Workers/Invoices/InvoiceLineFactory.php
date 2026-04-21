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
    public function build(stdClass $headerRow, array $detailRows, array $paymentRows): array
    {
        $headerAdapter = new LegacyInvoiceHeaderAdapter($this->hydrate(new PrototipoFacturaEncabezado(), $headerRow));
        $lines = [$headerAdapter->toLine()];
        $paymentCondition = strtoupper(trim((string) ($headerRow->f461_id_cond_pago ?? '')));

        if (in_array($paymentCondition, ['CONT', '1', '001', ''], true)) {
            foreach ($paymentRows as $paymentRow) {
                $lines[] = (new LegacyInvoicePaymentAdapter($this->hydrate(new PrototipoFacturaCaja(), $paymentRow)))->toLine();
            }
        } else {
            $lines[] = $headerAdapter->toAccountsReceivableLine();
        }

        foreach ($detailRows as $detailRow) {
            $adapter = new LegacyInvoiceLineAdapter($this->hydrate(new PrototipoFacturaDetalle(), $detailRow));
            $lines[] = $adapter->toLine(0);

            if ((float) ($detailRow->PorcentajeDescuento ?? 0) > 0 || (float) ($detailRow->FD_DescuentoValor ?? 0) > 0) {
                $lines[] = $adapter->toDiscountLine();
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
}
