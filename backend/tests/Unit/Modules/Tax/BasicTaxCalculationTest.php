<?php

namespace Tests\Unit\Modules\Tax;

use App\Modules\Tax\Support\Calculation\BasicTaxInput;
use App\Modules\Tax\Support\Calculation\CalculateBasicTax;
use App\Modules\Tax\Support\TaxDisposition;
use App\Modules\Tax\Support\TaxRefusal;
use Brick\Math\BigRational;
use PHPUnit\Framework\TestCase;

class BasicTaxCalculationTest extends TestCase
{
    public function test_tax_uses_exact_net_and_preserves_unknown_separate_from_known_zero(): void
    {
        $calculate = new CalculateBasicTax;
        $this->assertTrue($calculate(new BasicTaxInput(1, TaxDisposition::Taxable, '10', 'component-v1', 'determination-v1'), BigRational::of('1000'))->isEqualTo(100));
        foreach ([TaxDisposition::Exempt, TaxDisposition::NotApplicable, TaxDisposition::ZeroRated] as $disposition) {
            $this->assertTrue($calculate(new BasicTaxInput(1, $disposition, '0', 'component-v1', 'determination-v1'), BigRational::of('1000'))->isZero());
        }
        $this->assertNull($calculate(new BasicTaxInput(1, TaxDisposition::Unknown), BigRational::of('1000')));
    }

    public function test_it_does_not_settle_the_raw_tax_or_depend_on_sales_money(): void
    {
        $raw = (new CalculateBasicTax)(new BasicTaxInput(1, TaxDisposition::Taxable, '10', 'c', 'd'), BigRational::of('0.05'));
        $this->assertTrue($raw->isEqualTo('0.005'));
    }

    public function test_known_exemption_requires_provenance_and_cannot_be_faked_by_absence(): void
    {
        $this->expectException(TaxRefusal::class);
        $this->expectExceptionMessage('requires component and determination evidence');
        new BasicTaxInput(1, TaxDisposition::Exempt);
    }

    public function test_rate_shape_and_explicit_dispositions_follow_the_existing_tax_contract(): void
    {
        foreach (['-1', '101', '1e1', '0.0000000001', 'NaN', ' 5'] as $rate) {
            try {
                new BasicTaxInput(1, TaxDisposition::Taxable, $rate, 'c', 'd');
                $this->fail('Invalid rate accepted: '.$rate);
            } catch (TaxRefusal $e) {
                $this->assertSame('BASIC_TAX_INVALID', $e->reasonCode);
            }
        }
        foreach ([[TaxDisposition::Taxable, null], [TaxDisposition::Taxable, '0'], [TaxDisposition::ZeroRated, null], [TaxDisposition::ZeroRated, '10']] as [$kind, $rate]) {
            try {
                new BasicTaxInput(1, $kind, $rate, 'c', 'd');
                $this->fail('Inconsistent disposition accepted');
            } catch (TaxRefusal $e) {
                $this->assertSame('BASIC_TAX_INVALID', $e->reasonCode);
            }
        }
    }

    public function test_unsupported_methods_bases_and_inclusion_never_silently_calculate_as_basic(): void
    {
        foreach ([['FIXED_LINE', 'NET', false], ['PERCENT', 'TAX_AMOUNT', false], ['PERCENT', 'NET_PLUS_COMPONENTS', false], ['PERCENT', 'NET', true]] as [$method, $basis, $included]) {
            try {
                new BasicTaxInput(1, TaxDisposition::Taxable, '10', 'c', 'd', $method, $basis, $included);
                $this->fail('Unsupported calculation accepted');
            } catch (TaxRefusal $e) {
                $this->assertSame('TAX_CALCULATION_UNSUPPORTED', $e->reasonCode);
            }
        }
    }

    public function test_negative_scope_is_refused(): void
    {
        $this->expectException(TaxRefusal::class);
        (new CalculateBasicTax)(new BasicTaxInput(1, TaxDisposition::Unknown), BigRational::of('-1'));
    }
}
