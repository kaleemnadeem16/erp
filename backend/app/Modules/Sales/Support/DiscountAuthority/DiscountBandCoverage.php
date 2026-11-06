<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\DiscountAuthority;

use App\Modules\Sales\Models\SalesDiscountBand;
use App\Modules\Sales\Models\SalesDiscountPolicy;
use Brick\Math\BigRational;

/** No rounding in ceiling comparisons; a rounded display percentage cannot turn a breach into clearance. */
final class DiscountBandCoverage
{
    /** @param array<string, mixed> $measure */
    public function covers(SalesDiscountBand $band, SalesDiscountPolicy $policy, array $measure, bool $approval = false): bool
    {
        if ($band->exception_only) {
            return $approval;
        }
        if ($policy->enforcement !== 'LINE' && (! $this->within($measure['document_percent'], $band->document_percent)
            || ! $this->within($measure['reduction_base_exact'], $band->document_base_amount))) {
            return false;
        }
        foreach ($measure['groups'] as $group) {
            if (($policy->enforcement !== 'DOCUMENT' && (! $this->within($group['percent'], $band->line_percent) || ! $this->within($group['base'], $band->line_base_amount)))
                || ! $this->within($group['specific_percent'], $band->input_percent) || ! $this->within($group['specific_base'], $band->input_base_amount)) {
                return false;
            }
        }

        return true;
    }

    private function within(string $actual, ?string $ceiling): bool
    {
        return $ceiling === null || BigRational::of($actual)->isLessThanOrEqualTo($ceiling);
    }
}
