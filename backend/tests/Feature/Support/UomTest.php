<?php

namespace Tests\Feature\Support;

use App\Models\Uom;
use App\Support\UomDimension;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UomTest extends TestCase
{
    use RefreshDatabase;

    public function test_code_is_unique_per_company(): void
    {
        Uom::create(['code' => 'sqm', 'name' => 'Square metre', 'dimension' => UomDimension::Area, 'factor_to_base' => '1']);

        $this->expectException(QueryException::class);

        Uom::create(['code' => 'sqm', 'name' => 'Duplicate', 'dimension' => UomDimension::Area, 'factor_to_base' => '2']);
    }

    public function test_a_second_base_unit_for_the_same_dimension_and_company_is_rejected(): void
    {
        Uom::create(['code' => 'sqm', 'name' => 'Square metre', 'dimension' => UomDimension::Area, 'factor_to_base' => '1', 'is_base' => true]);

        $this->expectException(QueryException::class);

        Uom::create(['code' => 'sqft', 'name' => 'Square foot', 'dimension' => UomDimension::Area, 'factor_to_base' => '0.092903040', 'is_base' => true]);
    }

    public function test_each_dimension_may_have_its_own_base_unit(): void
    {
        Uom::create(['code' => 'sqm', 'name' => 'Square metre', 'dimension' => UomDimension::Area, 'factor_to_base' => '1', 'is_base' => true]);
        $litre = Uom::create(['code' => 'L', 'name' => 'Litre', 'dimension' => UomDimension::Volume, 'factor_to_base' => '1', 'is_base' => true]);

        $this->assertTrue($litre->is_base);
    }

    public function test_non_base_units_for_the_same_dimension_do_not_collide(): void
    {
        Uom::create(['code' => 'sqm', 'name' => 'Square metre', 'dimension' => UomDimension::Area, 'factor_to_base' => '1', 'is_base' => false]);
        $sqft = Uom::create(['code' => 'sqft', 'name' => 'Square foot', 'dimension' => UomDimension::Area, 'factor_to_base' => '0.092903040', 'is_base' => false]);

        $this->assertFalse($sqft->is_base);
    }
}
