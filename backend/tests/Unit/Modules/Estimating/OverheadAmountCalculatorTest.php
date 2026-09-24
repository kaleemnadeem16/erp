<?php

namespace Tests\Unit\Modules\Estimating;

use App\Modules\Estimating\Support\OverheadAmountCalculator;
use App\Modules\Estimating\Support\OverheadCostBasis;
use App\Modules\Estimating\Support\OverheadDriverType;
use Tests\TestCase;

/**
 * Pure function, no assembly_components rows needed — see
 * OverheadAmountCalculator's docblock. Figures below are chosen so each
 * driver's expected result is easy to verify by hand.
 */
class OverheadAmountCalculatorTest extends TestCase
{
    private OverheadAmountCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new OverheadAmountCalculator;
    }

    public function test_pct_material_applies_the_rate_to_material_cost_only(): void
    {
        $basis = new OverheadCostBasis(materialCost: '200', labourCost: '500');

        $result = ($this->calculator)(OverheadDriverType::PctMaterial, '15', $basis);

        $this->assertSame('30.0000', (string) $result);
    }

    public function test_pct_labour_applies_the_rate_to_labour_cost_only(): void
    {
        $basis = new OverheadCostBasis(materialCost: '200', labourCost: '500');

        $result = ($this->calculator)(OverheadDriverType::PctLabour, '10', $basis);

        $this->assertSame('50.0000', (string) $result);
    }

    public function test_pct_direct_cost_applies_the_rate_to_all_four_direct_components(): void
    {
        $basis = new OverheadCostBasis(materialCost: '100', labourCost: '100', plantCost: '50', subcontractCost: '50');

        $result = ($this->calculator)(OverheadDriverType::PctDirectCost, '20', $basis);

        // direct cost = 100 + 100 + 50 + 50 = 300; 20% of 300 = 60
        $this->assertSame('60.0000', (string) $result);
    }

    public function test_per_labour_hour_multiplies_the_rate_by_hours_worked(): void
    {
        $basis = new OverheadCostBasis(labourHours: '8');

        $result = ($this->calculator)(OverheadDriverType::PerLabourHour, '12.50', $basis);

        $this->assertSame('100.0000', (string) $result);
    }

    public function test_per_work_unit_multiplies_the_rate_by_the_assemblys_work_qty(): void
    {
        $basis = new OverheadCostBasis(workQty: '250');

        $result = ($this->calculator)(OverheadDriverType::PerWorkUnit, '2', $basis);

        $this->assertSame('500.0000', (string) $result);
    }

    public function test_an_empty_basis_produces_zero_for_every_driver(): void
    {
        $basis = new OverheadCostBasis;

        foreach (OverheadDriverType::cases() as $driver) {
            $result = ($this->calculator)($driver, '10', $basis);

            $this->assertSame('0.0000', (string) $result, $driver->value);
        }
    }
}
