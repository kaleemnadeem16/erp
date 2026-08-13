<?php

namespace Tests\Unit\Support;

use App\Models\Uom;
use App\Support\UomConversionService;
use App\Support\UomDimension;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use Tests\TestCase;

class UomConversionServiceTest extends TestCase
{
    private function uom(string $code, UomDimension $dimension, string $factorToBase): Uom
    {
        return new Uom([
            'code' => $code,
            'name' => $code,
            'dimension' => $dimension,
            'factor_to_base' => $factorToBase,
        ]);
    }

    public function test_sqft_converts_to_sqm(): void
    {
        $sqm = $this->uom('sqm', UomDimension::Area, '1.000000000');
        $sqft = $this->uom('sqft', UomDimension::Area, '0.092903040');

        $result = (new UomConversionService)->convert('10', $sqft, $sqm);

        $this->assertTrue($result->isEqualTo(BigDecimal::of('0.929030400')));
    }

    public function test_converting_the_same_unit_to_itself_is_a_no_op(): void
    {
        $sqm = $this->uom('sqm', UomDimension::Area, '1.000000000');

        $result = (new UomConversionService)->convert('42', $sqm, $sqm);

        $this->assertTrue($result->isEqualTo(BigDecimal::of('42.000000000')));
    }

    public function test_converting_across_dimensions_throws(): void
    {
        $sqm = $this->uom('sqm', UomDimension::Area, '1.000000000');
        $litre = $this->uom('L', UomDimension::Volume, '1.000000000');

        $this->expectException(InvalidArgumentException::class);

        (new UomConversionService)->convert('5', $litre, $sqm);
    }
}
