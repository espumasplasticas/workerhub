<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class OrderCancellationCommitmentReleaseServiceSourceTest extends TestCase
{
    public function test_it_queries_committed_order_lines_with_legacy_joins_instead_of_nonexistent_t431_columns(): void
    {
        $source = file_get_contents(
            'c:\\laragon\\www\\WorkerHub\\app\\Services\\Workers\\Orders\\OrderCancellationCommitmentReleaseService.php'
        );

        $this->assertIsString($source);
        $this->assertStringContainsString(
            't431.f431_rowid_item_ext = t121.f121_rowid',
            $source
        );
        $this->assertStringContainsString(
            't121.f121_rowid_item = t120.f120_rowid',
            $source
        );
        $this->assertStringContainsString(
            't431.f431_rowid_bodega = t150.f150_rowid',
            $source
        );
        $this->assertStringNotContainsString(
            'RTRIM(CONVERT(varchar(50), f431_id_item)) AS f431_id_item',
            $source
        );
    }
}
