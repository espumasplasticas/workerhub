<?php

namespace Tests\Unit;

use App\Services\Workers\Invoices\InvoiceLineFactory;
use Tests\TestCase;

class InvoiceLineFactoryTest extends TestCase
{
    public function test_it_appends_adjustment_payment_when_adjustment_non_zero_and_payments_present(): void
    {
        $factory = new InvoiceLineFactory();

        $header = (object) [
            'F350_ID_CO' => '002',
            'F350_ID_TIPO_DOCTO' => 'F4',
            'F350_CONSEC_DOCTO' => '123',
            'FE_FormaDePago' => '0',
        ];

        $paymentRows = [
            (object) [
                'F358_ID_MEDIOS_PAGO' => '001',
                'F358_ID_MONEDA' => '001',
                'F_VLR_MEDIO_PAGO' => 1000,
            ],
        ];

        $lines = $factory->build($header, [], $paymentRows, 5.0, []);

        // header + original payment + adjustment
        $this->assertCount(3, $lines);
        $this->assertStringStartsWith('0358', $lines[1]);
        $this->assertStringStartsWith('0358', $lines[2]);
    }

    public function test_it_emits_adjustment_when_no_payments(): void
    {
        $factory = new InvoiceLineFactory();

        $header = (object) [
            'F350_ID_CO' => '002',
            'F350_ID_TIPO_DOCTO' => 'F4',
            'F350_CONSEC_DOCTO' => '124',
            'FE_FormaDePago' => '0',
        ];

        $lines = $factory->build($header, [], [], 10.0, []);

        // header + adjustment
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('0358', $lines[1]);
    }

    public function test_it_does_not_add_adjustment_when_zero(): void
    {
        $factory = new InvoiceLineFactory();

        $header = (object) [
            'F350_ID_CO' => '002',
            'F350_ID_TIPO_DOCTO' => 'F4',
            'F350_CONSEC_DOCTO' => '125',
            'FE_FormaDePago' => '0',
        ];

        $paymentRows = [
            (object) [
                'F358_ID_MEDIOS_PAGO' => '001',
                'F358_ID_MONEDA' => '001',
                'F_VLR_MEDIO_PAGO' => 1000,
            ],
        ];

        $lines = $factory->build($header, [], $paymentRows, 0.0, []);

        // header + single payment
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('0358', $lines[1]);
    }
}
