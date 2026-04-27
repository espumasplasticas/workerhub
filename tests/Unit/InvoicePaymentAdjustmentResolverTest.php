<?php

namespace Tests\Unit;

use App\Services\Workers\Invoices\InvoiceLegacyAmountCalculator;
use App\Services\Workers\Invoices\InvoicePaymentAdjustmentResolver;
use Tests\TestCase;

class InvoicePaymentAdjustmentResolverTest extends TestCase
{
    public function test_it_uses_the_legacy_detail_vs_payment_difference_for_the_first_attempts(): void
    {
        $resolver = new InvoicePaymentAdjustmentResolver(new InvoiceLegacyAmountCalculator());

        $header = (object) ['FE_FormaDePago' => '0'];
        $detailRows = [
            (object) [
                'f470_cant_base' => 1,
                'FD_PrecioUnitario' => 1000,
                'FD_TasaIVA' => 0,
                'FD_IndicadorImpuestoIncluido' => 0,
                'FD_TasaDescuento' => 0,
                'FD_DescuentoValor' => 0,
                'FD_TasaDescuento2' => 0,
                'FD_DescuentoValor2' => 0,
                'FD_ValorImpConsumo' => 0,
            ],
        ];
        $paymentRows = [
            (object) ['F_VLR_MEDIO_PAGO' => 998],
        ];

        $this->assertSame(2.0, $resolver->resolveCashPaymentAdjustment($header, $detailRows, $paymentRows, 1));
    }

    public function test_it_uses_the_alternating_legacy_retry_sequence_after_the_sixth_attempt(): void
    {
        $resolver = new InvoicePaymentAdjustmentResolver(new InvoiceLegacyAmountCalculator());
        $header = (object) ['FE_FormaDePago' => '0'];

        $this->assertSame(0.0, $resolver->resolveCashPaymentAdjustment($header, [], [], 7));
        $this->assertSame(1.0, $resolver->resolveCashPaymentAdjustment($header, [], [], 8));
        $this->assertSame(-1.0, $resolver->resolveCashPaymentAdjustment($header, [], [], 9));
        $this->assertSame(10.0, $resolver->resolveCashPaymentAdjustment($header, [], [], 26));
        $this->assertSame(-10.0, $resolver->resolveCashPaymentAdjustment($header, [], [], 27));
    }
}
