<?php

namespace Tests\Unit;

use App\Services\Workers\Invoices\InvoiceLegacyAmountCalculator;
use Tests\TestCase;

class InvoiceLegacyAmountCalculatorTest extends TestCase
{
    public function test_it_calculates_the_legacy_invoice_net_total_using_the_same_rounding_strategy_as_the_legacy_exporter(): void
    {
        $calculator = new InvoiceLegacyAmountCalculator();

        $detailRows = [
            (object) [
                'f470_cant_base' => 2,
                'FD_PrecioUnitario' => 59500,
                'FD_TasaIVA' => 19,
                'FD_IndicadorImpuestoIncluido' => 1,
                'FD_TasaDescuento' => 0,
                'FD_DescuentoValor' => 0,
                'FD_TasaDescuento2' => 0,
                'FD_DescuentoValor2' => 0,
                'FD_ValorImpConsumo' => 0,
            ],
            (object) [
                'f470_cant_base' => 1,
                'FD_PrecioUnitario' => 2000,
                'FD_TasaIVA' => 0,
                'FD_IndicadorImpuestoIncluido' => 0,
                'FD_TasaDescuento' => 0,
                'FD_DescuentoValor' => 0,
                'FD_TasaDescuento2' => 0,
                'FD_DescuentoValor2' => 0,
                'FD_ValorImpConsumo' => 500,
            ],
        ];

        $this->assertSame(121500.0, $calculator->calculateLegacyNetTotalFromDetails($detailRows));
    }

    public function test_it_calculates_the_legacy_rounded_retry_adjustment_ignoring_consumption_tax_rows(): void
    {
        $calculator = new InvoiceLegacyAmountCalculator();

        $detailRows = [
            (object) [
                'FD_ValorNeto' => 1000,
                'FD_PrecioUnitario' => 1000,
                'FD_TasaDescuento' => 0,
                'FD_TasaDescuento2' => 0,
                'FD_CantidadFacturada' => 1,
                'FD_ValorImpConsumo' => 0,
            ],
            (object) [
                'FD_ValorNeto' => 1000,
                'FD_PrecioUnitario' => 1000,
                'FD_TasaDescuento' => 0,
                'FD_TasaDescuento2' => 0,
                'FD_CantidadFacturada' => 1,
                'FD_ValorImpConsumo' => 50,
            ],
        ];

        $this->assertSame(0.0, $calculator->calculateRoundedRetryAdjustment($detailRows));
    }
}
