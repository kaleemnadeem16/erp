<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\DiscountAuthority;

use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Fx\FinancialDate;
use App\Modules\Sales\Support\Fx\FxBundleEvidence;
use App\Modules\Sales\Support\Kernel\OperationIntent;
use Brick\Math\BigRational;
use Throwable;

/** Validates stored QuotationMoney evidence; never queries the rate master or reconstructs a rate from rounded money. */
final class PinnedAuthorityFx
{
    public function ratio(QuotationRevision $revision, QuotationDraftLine $line, int $baseCurrencyId): BigRational
    {
        try {
            $evidence = $line->fx_evidence;
            if ($evidence === null || $line->fx_fingerprint === null || $line->base_currency_id !== $baseCurrencyId || $line->price_currency_id !== $revision->currency_id) {
                throw new \InvalidArgumentException;
            }
            $content = $evidence;
            unset($content['observed_at_utc']);
            if (! hash_equals($line->fx_fingerprint, OperationIntent::hash('fr1.fx_bundle', $content))
                || $evidence['company_id'] !== $revision->company_id || $evidence['source_currency_id'] !== $revision->currency_id
                || $evidence['quote_currency_id'] !== $baseCurrencyId || $evidence['base_currency_id'] !== $baseCurrencyId
                || $evidence['requested_date'] !== $revision->pricing_date?->format('Y-m-d') || $evidence['calculation_version'] !== FxBundleEvidence::CALCULATION_VERSION) {
                throw new \InvalidArgumentException;
            }
            $ratio = BigRational::of($evidence['ratio']['numerator'])->dividedBy($evidence['ratio']['denominator']);
            if (! $ratio->isPositive()) {
                throw new \InvalidArgumentException;
            }
            if ($evidence['route'] === FxBundleEvidence::ROUTE_IDENTITY) {
                if ($revision->currency_id !== $baseCurrencyId || ! $ratio->isEqualTo(1) || $evidence['source_to_base_leg'] !== null || $evidence['quote_to_base_leg'] !== null) {
                    throw new \InvalidArgumentException;
                }
            } elseif ($evidence['route'] === FxBundleEvidence::ROUTE_COMPANY_BASE_BRIDGE && $revision->currency_id !== $baseCurrencyId) {
                $source = $evidence['source_to_base_leg'];
                $target = $evidence['quote_to_base_leg'];
                $date = FinancialDate::explicit($evidence['requested_date'], $evidence['time_zone'], now());
                $age = $date->daysSince($source['actual_date']);
                if ($source['pair'] !== [$revision->currency_id, $baseCurrencyId] || $target['pair'] !== [$baseCurrencyId, $baseCurrencyId]
                    || $source['identity'] !== false || $target['identity'] !== true || $source['rate_type'] !== 'SPOT' || $target['rate_type'] !== 'SPOT'
                    || trim((string) $source['source']) === '' || $source['rate_master_id'] === null || $source['requested_date'] !== $date->date
                    || $target['requested_date'] !== $date->date || $target['actual_date'] !== $date->date || $target['age_days'] !== 0
                    || $target['source'] !== 'IDENTITY' || $target['rate_master_id'] !== null || $evidence['policy']['max_age_days'] < 0
                    || $age < 0 || $age !== $source['age_days'] || $age > $evidence['policy']['max_age_days']
                    || ! in_array($evidence['policy']['mode'], ['EXACT_DATE', 'BOUNDED_PRIOR'], true)
                    || ($evidence['policy']['mode'] === 'EXACT_DATE' && $age !== 0)
                    || ! BigRational::of($target['rate'])->isEqualTo(1) || ! $ratio->isEqualTo(BigRational::of($source['rate']))) {
                    throw new \InvalidArgumentException;
                }
            } else {
                throw new \InvalidArgumentException;
            }

            return $ratio;
        } catch (Throwable) {
            throw AuthorityRefusal::make('AUTHORITY_FX_UNVERIFIED', 'The quotation has missing or inconsistent pinned exchange-rate evidence. Refresh its pricing evidence before review.');
        }
    }
}
