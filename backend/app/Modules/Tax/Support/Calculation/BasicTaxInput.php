<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support\Calculation;

use App\Modules\Tax\Support\TaxDisposition;
use App\Modules\Tax\Support\TaxRefusal;
use Brick\Math\BigDecimal;

/** Already-determined server facts, not a determination API or proof of exemption. No model lookup or permission is implied by constructing this value. */
final readonly class BasicTaxInput
{
    public ?BigDecimal $percentage;

    public function __construct(
        public int $companyId,
        public TaxDisposition $disposition,
        ?string $percentage = null,
        public ?string $componentReference = null,
        public ?string $determinationReference = null,
        public string $method = 'PERCENT',
        public string $basis = 'NET',
        public bool $included = false,
    ) {
        if ($companyId < 1) {
            throw TaxRefusal::because('BASIC_TAX_INVALID', 'A tax calculation needs a company.');
        }
        if ($method !== 'PERCENT' || $basis !== 'NET' || $included) {
            throw TaxRefusal::because('TAX_CALCULATION_UNSUPPORTED', 'BASIC calculation supports one excluded PERCENT/NET output component only.');
        }
        if ($percentage !== null && preg_match('/^\d+(\.\d+)?$/', $percentage) !== 1) {
            throw TaxRefusal::because('BASIC_TAX_INVALID', 'A tax percentage must be a plain non-negative decimal.');
        }
        $value = $percentage === null ? null : BigDecimal::of($percentage);
        if ($value !== null && ($value->isGreaterThan(100) || $value->strippedOfTrailingZeros()->getScale() > 9)) {
            throw TaxRefusal::because('BASIC_TAX_INVALID', 'The percentage must fit the Tax rate contract (zero to 100, nine decimal places).');
        }
        if ($disposition !== TaxDisposition::Unknown && (trim($componentReference ?? '') === '' || trim($determinationReference ?? '') === '')) {
            throw TaxRefusal::because('TAX_EVIDENCE_REQUIRED', 'A known disposition requires component and determination evidence.');
        }
        if (($disposition === TaxDisposition::Taxable && ($value === null || ! $value->isPositive())) || ($disposition === TaxDisposition::ZeroRated && ($value === null || ! $value->isZero()))) {
            throw TaxRefusal::because('BASIC_TAX_INVALID', 'Taxable needs a positive rate; zero-rated needs an explicit zero rate.');
        }
        $this->percentage = $value;
    }
}
