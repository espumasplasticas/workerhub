<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class OrderCancellationOperationalSideEffectsSourceTest extends TestCase
{
    public function test_it_preserves_domicile_bitacoras_before_unlinking_during_order_cancellation(): void
    {
        $serviceSource = file_get_contents(
            'c:\\laragon\\www\\WorkerHub\\app\\Services\\Workers\\Orders\\OrderCancellationOperationalSideEffectsService.php'
        );
        $preservationSource = file_get_contents(
            'c:\\laragon\\www\\WorkerHub\\app\\Services\\Workers\\Orders\\OrderDomicileBitacoraPreservationService.php'
        );
        $configSource = file_get_contents(
            'c:\\laragon\\www\\WorkerHub\\config\\workerhub.php'
        );

        $this->assertIsString($serviceSource);
        $this->assertIsString($preservationSource);
        $this->assertIsString($configSource);
        $this->assertStringContainsString('preserveDomicileBitacorasAsOrderNotes', $serviceSource);
        $this->assertStringContainsString("table(\$this->orderNotesTable())", $preservationSource);
        $this->assertStringContainsString("'order_notes' => env('WORKERHUB_ORDER_NOTES_TABLE', 'pos.notas_pedidos')", $configSource);
    }
}
