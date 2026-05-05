<?php

namespace Tests\Unit\Orders;

use App\Services\Workers\Orders\OrderHeaderReferenceResolver;
use stdClass;
use Tests\TestCase;

class OrderHeaderReferenceResolverTest extends TestCase
{
    public function test_it_keeps_existing_reference_fields_from_the_header(): void
    {
        $resolver = new OrderHeaderReferenceResolver();
        $header = (object) [
            'f430_num_docto_referencia' => 'OC-123456789',
            'f430_referencia' => 'REF-01',
            'PE_OrdenDeCompra' => 'OC-IGNORED',
            'PE_OrdenDeCargue' => 'LOAD-99',
        ];

        $resolved = $resolver->resolve($header);

        $this->assertSame('OC-123456789', $resolved['reference_document_number']);
        $this->assertSame('REF-01', $resolved['reference']);
    }

    public function test_it_falls_back_to_legacy_order_fields_when_prototype_reference_fields_are_empty(): void
    {
        $resolver = new OrderHeaderReferenceResolver();
        $header = (object) [
            'f430_num_docto_referencia' => '',
            'f430_referencia' => '',
        ];
        $orderRecord = (object) [
            'PE_OrdenDeCompra' => 'OC-22846-EXT',
            'PE_OrdenDeCargue' => 'LOAD-22846-XYZ',
        ];

        $resolved = $resolver->resolve($header, $orderRecord);

        $this->assertSame('OC-22846-EXT', $resolved['reference_document_number']);
        $this->assertSame('LOAD-22846', $resolved['reference']);
    }

    public function test_it_uses_purchase_order_as_last_resort_reference_when_no_load_order_exists(): void
    {
        $resolver = new OrderHeaderReferenceResolver();
        $header = (object) [
            'f430_num_docto_referencia' => '',
            'f430_referencia' => '',
            'PE_OrdenDeCompra' => 'OC-ONLY',
        ];

        $resolved = $resolver->resolve($header);

        $this->assertSame('OC-ONLY', $resolved['reference_document_number']);
        $this->assertSame('OC-ONLY', $resolved['reference']);
    }

    public function test_it_falls_back_to_order_document_number_when_no_external_references_exist(): void
    {
        $resolver = new OrderHeaderReferenceResolver();
        $header = (object) [
            'f430_num_docto_referencia' => '',
            'f430_referencia' => '',
            'PE_NumeroDocumento' => '22847',
        ];

        $resolved = $resolver->resolve($header);

        $this->assertSame('22847', $resolved['reference_document_number']);
        $this->assertSame('22847', $resolved['reference']);
    }
}
