<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Tax\Support\TaxClassification;
use App\Modules\Tax\Support\TaxDetermination;
use App\Modules\Tax\Support\TaxDisposition;
use App\Modules\Tax\Support\TaxNature;
use App\Modules\Tax\Support\TaxSupport;

/** Pure FR2a classification boundary. Does not read or calculate Party, group, rate or monetary facts. */
final class DetermineQuotationTax
{
    public function __invoke(TaxClassification $classification): TaxDetermination
    {
        if ($classification->nature === null || ($classification->code === null || trim($classification->code) === '')) {
            return new TaxDetermination(TaxSupport::Unknown, TaxDisposition::Unknown, 'CLASSIFICATION_MISSING');
        }
        if ($classification->nature === TaxNature::Both) {
            return new TaxDetermination(TaxSupport::Unsupported, TaxDisposition::Unknown, 'LINE_NATURE_AMBIGUOUS');
        }

        // The known source classification is supported, but statutory disposition is deliberately unknown until FR2b/FR3.
        return new TaxDetermination(TaxSupport::Supported, TaxDisposition::Unknown, 'CLASSIFICATION_ONLY_PROFILE_AND_CALCULATION_PENDING');
    }
}
