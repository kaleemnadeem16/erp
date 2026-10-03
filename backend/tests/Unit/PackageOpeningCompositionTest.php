<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Support\PackageOpeningComposition;
use PHPUnit\Framework\TestCase;

class PackageOpeningCompositionTest extends TestCase
{
    public function test_open_contents_are_combined_without_merging_held_stock(): void
    {
        $sealed = $this->sealed();
        $loose = array_replace($sealed, ['state_id' => 2, 'form' => 'OPEN_LOOSE', 'quantity' => '1.0000',
            'sealed_count' => null, 'content_quantity' => null, 'packaging_id' => null,
            'contributions' => [['layer_id' => 10, 'position' => 1, 'quantity' => '1.0000']]]);
        $held = array_replace($loose, ['state_id' => 3, 'availability_evidence' => 'HELD']);
        $result = (new PackageOpeningComposition)([$sealed, $loose, $held], 1, 1, 'OPEN');
        $usable = array_values(array_filter($result, fn ($row) => $row['form'] === 'OPEN_LOOSE' && $row['availability_evidence'] === 'USABLE'));
        $this->assertCount(1, $usable);
        $this->assertSame('3.0000', $usable[0]['quantity']);
        $this->assertSame(['2.0000', '1.0000'], array_column($usable[0]['contributions'], 'quantity'));
        $this->assertContains($held, $result);
    }

    public function test_held_sealed_packages_cannot_be_opened(): void
    {
        $this->expectException(DomainInvariantException::class);
        (new PackageOpeningComposition)([array_replace($this->sealed(), ['availability_evidence' => 'HELD'])], 1, 1, 'OPEN');
    }

    public function test_non_reconciling_inner_packages_are_refused(): void
    {
        $this->expectException(DomainInvariantException::class);
        (new PackageOpeningComposition)([$this->sealed()], 1, 1, 'UNPACK', ['content_quantity' => '0.1'], 19);
    }

    public function test_sub_precision_contents_are_refused(): void
    {
        $this->expectException(DomainInvariantException::class);
        (new PackageOpeningComposition)([$this->sealed()], 1, 1, 'UNPACK', ['content_quantity' => '0.00001'], 200000);
    }

    public function test_zero_count_is_refused(): void
    {
        $this->expectException(DomainInvariantException::class);
        (new PackageOpeningComposition)([$this->sealed()], 1, 0, 'OPEN');
    }

    private function sealed(): array
    {
        return ['state_id' => 1, 'form' => 'SEALED', 'availability_evidence' => 'USABLE',
            'packaging_id' => 1, 'packaging_code' => 'carton', 'packaging_name' => 'Two litre carton',
            'sealed_count' => 2, 'content_quantity' => '2.0000', 'quantity' => '4.0000',
            'contributions' => [['layer_id' => 10, 'position' => 1, 'quantity' => '1.0000'],
                ['layer_id' => 11, 'position' => 2, 'quantity' => '3.0000']]];
    }
}
