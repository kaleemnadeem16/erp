<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support\Calculation;

use App\Modules\Tax\Support\TaxDisposition;
use App\Modules\Tax\Support\TaxRefusal;
use Brick\Math\BigRational;

/** FR3 BASIC pure raw calculation. Sales settles the component under its pinned currency policy; Tax owns no Sales money/rounding implementation. */
final class CalculateBasicTax
{
    public function __invoke(BasicTaxInput $input, BigRational $settledNet): ?BigRational
    {
        if ($settledNet->isNegative()) {
            throw TaxRefusal::because('BASIC_TAX_INVALID', 'Negative Sales scope is unsupported.');
        }

        return match ($input->disposition) {
            TaxDisposition::Unknown => null,
            TaxDisposition::Exempt, TaxDisposition::NotApplicable, TaxDisposition::ZeroRated => BigRational::zero(),
            TaxDisposition::Taxable => $settledNet->multipliedBy($input->percentage ?? throw TaxRefusal::because('BASIC_TAX_INVALID', 'Taxable needs a rate.'))->dividedBy(100),
        };
    }
}
