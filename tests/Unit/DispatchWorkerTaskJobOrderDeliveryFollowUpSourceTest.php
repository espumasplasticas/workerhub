<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DispatchWorkerTaskJobOrderDeliveryFollowUpSourceTest extends TestCase
{
    public function test_it_enqueues_an_independent_order_delivery_generation_follow_up_after_order_migration(): void
    {
        $source = file_get_contents(
            'c:\\laragon\\www\\WorkerHub\\app\\Jobs\\DispatchWorkerTaskJob.php'
        );

        $this->assertIsString($source);
        $this->assertStringContainsString("'order_delivery_generation'", $source);
        $this->assertStringContainsString("'process_key' => 'deliveries'", $source);
        $this->assertStringContainsString("'process_label' => 'Domicilios'", $source);
        $this->assertStringContainsString("'task.follow_up.order_delivery_generation'", $source);
    }
}
