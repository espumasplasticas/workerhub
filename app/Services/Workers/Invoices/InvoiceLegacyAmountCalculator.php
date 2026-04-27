<?php

namespace App\Services\Workers\Invoices;

use stdClass;

class InvoiceLegacyAmountCalculator
{
    /**
     * Calcula el neto legacy de la factura a partir del detalle prototipo.
     *
     * @param list<stdClass> $detailRows
     */
    public function calculateLegacyNetTotalFromDetails(array $detailRows): float
    {
        $grossValue = 0.0;
        $discountValue = 0.0;
        $vatValue = 0.0;
        $consumptionTaxValue = 0.0;

        foreach ($detailRows as $detailRow) {
            if (!$detailRow instanceof stdClass) {
                continue;
            }

            $priceSnapshot = $this->calculateLegacyPriceSnapshot($detailRow);
            $baseQuantity = (float) ($detailRow->f470_cant_base ?? 0);

            $grossValue += round($priceSnapshot['gross_value'] * $baseQuantity);
            $discountValue += round($priceSnapshot['discount_value'] * $baseQuantity);
            $vatValue += round($priceSnapshot['vat_value'] * $baseQuantity);
            $consumptionTaxValue += round((float) ($detailRow->FD_ValorImpConsumo ?? 0) * $baseQuantity);
        }

        return $grossValue - $discountValue + $vatValue + $consumptionTaxValue;
    }

    /**
     * @param list<stdClass> $paymentRows
     */
    public function calculateCollectedPaymentTotal(array $paymentRows): float
    {
        $total = 0.0;

        foreach ($paymentRows as $paymentRow) {
            if (!$paymentRow instanceof stdClass) {
                continue;
            }

            $total += (float) ($paymentRow->F_VLR_MEDIO_PAGO ?? 0);
        }

        return $total;
    }

    /**
     * Reproduce la segunda fase legacy del ajuste, basada en redondeos por linea.
     *
     * @param list<stdClass> $detailRows
     */
    public function calculateRoundedRetryAdjustment(array $detailRows): float
    {
        $difference = 0.0;

        foreach ($detailRows as $detailRow) {
            if (!$detailRow instanceof stdClass) {
                continue;
            }

            if ((float) ($detailRow->FD_ValorImpConsumo ?? 0) !== 0.0) {
                continue;
            }

            $legacyNetValue = (float) ($detailRow->FD_ValorNeto ?? 0);

            $precioUnitario = round((float) ($detailRow->FD_PrecioUnitario ?? 0), 0);
            $tasaDesc1 = (float) ($detailRow->FD_TasaDescuento ?? 0) / 100.0;
            $tasaDesc2 = (float) ($detailRow->FD_TasaDescuento2 ?? 0) / 100.0;
            $cantidad = (float) ($detailRow->FD_CantidadFacturada ?? 0);

            $precioSinIva = round($precioUnitario / 1.19, 0);
            $precioConDesc1 = round($precioSinIva * (1 - $tasaDesc1), 0);
            $precioConDesc2 = round($precioConDesc1 * (1 - $tasaDesc2), 0);
            $valorNetoPorCantidad = round($precioConDesc2 * $cantidad, 0);
            $legacyRoundedNetValue = round($valorNetoPorCantidad * 1.19, 0);

            $detailDifference = $legacyNetValue - $legacyRoundedNetValue;

            if ($detailDifference !== 0.0) {
                $difference += $detailDifference;
            }
        }

        return $difference;
    }

    /**
     * @return array{gross_value: float, discount_value: float, vat_value: float}
     */
    private function calculateLegacyPriceSnapshot(stdClass $detailRow): array
    {
        $price = (float) ($detailRow->FD_PrecioUnitario ?? 0);
        $vatRate = (float) ($detailRow->FD_TasaIVA ?? 0) / 100;
        $discountRate = (float) ($detailRow->FD_TasaDescuento ?? 0) / 100;
        $discountRate2 = (float) ($detailRow->FD_TasaDescuento2 ?? 0) / 100;
        $discountValue = (float) ($detailRow->FD_DescuentoValor ?? 0);
        $discountValue2 = (float) ($detailRow->FD_DescuentoValor2 ?? 0);
        $taxIncluded = (int) ($detailRow->FD_IndicadorImpuestoIncluido ?? 0) === 1;

        if ($taxIncluded) {
            if ($discountValue === 0.0 && $discountValue2 === 0.0) {
                $grossValue = round($price / (1 + $vatRate), 0);
            } else {
                $grossValue = $price - $discountValue - $discountValue2;
                $grossValue = $grossValue / (1 + $vatRate);
                $grossValue = round($grossValue + $discountValue + $discountValue2, 0);
            }
        } else {
            $grossValue = $price;
        }

        if ($discountValue > 0) {
            $resolvedDiscountValue = $discountValue;
        } else {
            $resolvedDiscountValue = round($grossValue * $discountRate, 0);
        }

        if ($discountValue2 > 0) {
            $resolvedDiscountValue2 = $discountValue2;
        } else {
            $resolvedDiscountValue2 = round(($grossValue - $resolvedDiscountValue) * $discountRate2, 0);
        }

        $resolvedVatValue = round(($grossValue - $resolvedDiscountValue - $resolvedDiscountValue2) * $vatRate, 0);

        return [
            'gross_value' => $grossValue,
            'discount_value' => $resolvedDiscountValue + $resolvedDiscountValue2,
            'vat_value' => $resolvedVatValue,
        ];
    }
}
