<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

use Carbon\CarbonImmutable;

/** The identity of one counter within a series: the scope's period and dimension values, resolved for a document. */
final class CounterKey
{
    /** @param  array<string, string>  $dimensionValues */
    public static function build(CounterScope $scope, ?CarbonImmutable $local, ?FiscalPosition $fiscal, array $dimensionValues): string
    {
        $parts = [];
        $period = match ($scope->period) {
            ScopePeriod::None => null,
            ScopePeriod::Year => 'Y'.$local?->format('Y'),
            ScopePeriod::Month => 'M'.$local?->format('Y-m'),
            ScopePeriod::Day => 'D'.$local?->format('Y-m-d'),
            ScopePeriod::FiscalYear => 'FY'.$fiscal?->yearKey,
            ScopePeriod::FiscalPeriod => 'FP'.$fiscal?->periodKey,
        };
        if ($period !== null) {
            $parts[] = $period;
        }
        foreach ($scope->dimensions as $dimension) {
            $parts[] = $dimension.'='.($dimensionValues[$dimension] ?? '');
        }

        return $parts === [] ? 'ALL' : implode('|', $parts);
    }
}
