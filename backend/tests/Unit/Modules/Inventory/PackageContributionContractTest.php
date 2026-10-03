<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Inventory;

use Brick\Math\Exception\RoundingNecessaryException;
use DomainException;
use PHPUnit\Framework\TestCase;
use Tests\Support\PackageContributionPrototype;

final class PackageContributionContractTest extends TestCase
{
    public function test_partial_sealed_selection_spans_layers_without_fractional_packages(): void
    {
        $result = (new PackageContributionPrototype)->select(
            4, '5', 1, [11 => '1', 12 => '19'], [11 => '1', 12 => '4'], [11 => '1', 12 => '19'],
        );
        $this->assertSame([11 => '1.0000', 12 => '4.0000'], $result['selected']);
        $this->assertSame([12 => '15.0000'], $result['remaining']);
    }

    public function test_repeated_selection_reconciles_the_remaining_cohort(): void
    {
        $planner = new PackageContributionPrototype;
        $first = $planner->select(4, '5', 1, [11 => '1', 12 => '19'], [11 => '1', 12 => '4'], [11 => '1', 12 => '19']);
        $second = $planner->select(3, '5', 3, $first['remaining'], [12 => '15'], [12 => '15']);
        $this->assertSame([12 => '15.0000'], $second['selected']);
        $this->assertSame([], $second['remaining']);
    }

    public function test_selection_does_not_override_fifo_with_a_newer_receipt(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('differs from the stock allocation');
        (new PackageContributionPrototype)->select(1, '5', 1, [12 => '5'], [11 => '5'], [12 => '5']);
    }

    public function test_partial_qa_release_cannot_make_a_whole_sealed_package_usable(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('held stock');
        (new PackageContributionPrototype)->select(1, '5', 1, [11 => '5'], [11 => '5'], [11 => '1']);
    }

    public function test_full_qa_release_allows_the_whole_package(): void
    {
        $result = (new PackageContributionPrototype)->select(1, '5', 1, [11 => '5'], [11 => '5'], [11 => '5']);
        $this->assertSame([11 => '5.0000'], $result['selected']);
    }

    public function test_transfer_preserves_cross_layer_contributions(): void
    {
        $planner = new PackageContributionPrototype;
        $selection = $planner->select(4, '5', 1, [11 => '1', 12 => '19'], [11 => '1', 12 => '4'], [11 => '1', 12 => '19']);
        $this->assertSame([21 => '1.0000', 22 => '4.0000'], $planner->transfer($selection['selected'], [11 => 21, 12 => 22]));
    }

    public function test_transfer_refuses_missing_destination_attribution(): void
    {
        $this->expectException(DomainException::class);
        (new PackageContributionPrototype)->transfer([11 => '1', 12 => '4'], [11 => 21]);
    }

    public function test_transfer_refuses_collapsed_destination_layers(): void
    {
        $this->expectException(DomainException::class);
        (new PackageContributionPrototype)->transfer([11 => '1', 12 => '4'], [11 => 21, 12 => 21]);
    }

    public function test_count_checkpoint_refuses_a_quantity_mismatch(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('do not reconcile');
        (new PackageContributionPrototype)->select(4, '5', 1, [11 => '19'], [11 => '5'], [11 => '19']);
    }

    public function test_selection_refuses_more_packages_than_counted(): void
    {
        $this->expectException(DomainException::class);
        (new PackageContributionPrototype)->select(1, '5', 2, [11 => '5'], [11 => '5'], [11 => '5']);
    }

    public function test_content_finer_than_stock_precision_is_not_rounded(): void
    {
        $this->expectException(RoundingNecessaryException::class);
        (new PackageContributionPrototype)->select(1, '0.00001', 1, [11 => '0.00001'], [11 => '0.00001'], [11 => '0.00001']);
    }

    public function test_small_decimal_packages_remain_exact(): void
    {
        $result = (new PackageContributionPrototype)->select(3, '0.1', 2, [11 => '0.1', 12 => '0.2'], [11 => '0.1', 12 => '0.1'], [11 => '0.1', 12 => '0.2']);
        $this->assertSame([11 => '0.1000', 12 => '0.1000'], $result['selected']);
        $this->assertSame([12 => '0.1000'], $result['remaining']);
    }
}
