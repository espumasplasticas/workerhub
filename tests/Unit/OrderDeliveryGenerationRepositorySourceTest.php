<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class OrderDeliveryGenerationRepositorySourceTest extends TestCase
{
    public function test_it_uses_a_configurable_enterprise_detail_view_for_delivery_generation(): void
    {
        $repositorySource = file_get_contents(
            'c:\\laragon\\www\\WorkerHub\\app\\Services\\Workers\\Orders\\OrderDeliveryGenerationRepository.php'
        );
        $configSource = file_get_contents(
            'c:\\laragon\\www\\WorkerHub\\config\\workerhub.php'
        );

        $this->assertIsString($repositorySource);
        $this->assertIsString($configSource);
        $this->assertStringContainsString('private function enterpriseOrderDetailView()', $repositorySource);
        $this->assertStringContainsString('$this->enterpriseOrderDetailView()', $repositorySource);
        $this->assertStringContainsString("'order_detail_view' => env('WORKERHUB_ORDER_ENTERPRISE_ORDER_DETAIL_VIEW', 'SiesaEnterprise.dbo.v431')", $configSource);
    }
}
