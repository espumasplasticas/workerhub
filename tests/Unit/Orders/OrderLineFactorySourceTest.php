<?php

namespace Tests\Unit\Orders;

use Tests\TestCase;

class OrderLineFactorySourceTest extends TestCase
{
    public function test_it_resolves_legacy_movement_cost_center_before_building_order_lines(): void
    {
        $source = file_get_contents(app_path('Services/Workers/Orders/OrderLineFactory.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('resolveLegacyMovementCostCenter', $source);
        $this->assertStringContainsString('findDetailOnlyCostCenter', $source);
        $this->assertStringContainsString('findEquivalentCostCenter', $source);
        $this->assertStringContainsString('fallbackLegacyMovementCostCenter', $source);
        $this->assertStringContainsString('$detail->CentroDeCosto = $resolvedCostCenter;', $source);
    }
}
