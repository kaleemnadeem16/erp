<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * One ordered comparison in ADR 0053's lexicographic selection presets. Each
 * preset is a fixed list of these; the allocator reports every one it could
 * not apply (missing evidence) instead of silently dropping it.
 */
enum AllocationCriterion: string
{
    case OpenRemnants = 'OPEN_REMNANTS';
    case EarliestExpiry = 'EARLIEST_EXPIRY';
    case LeastSurplus = 'LEAST_SURPLUS';
    case FewestNewPackages = 'FEWEST_NEW_PACKAGES';
    case LowestInternalCost = 'LOWEST_INTERNAL_COST';
    case FewestPicks = 'FEWEST_PICKS';

    public function label(): string
    {
        return match ($this) {
            self::OpenRemnants => 'Use open remnants first',
            self::EarliestExpiry => 'Earliest expiry first',
            self::LeastSurplus => 'Least surplus',
            self::FewestNewPackages => 'Fewest newly opened packages',
            self::LowestInternalCost => 'Lowest internal cost',
            self::FewestPicks => 'Fewest physical picks',
        };
    }
}
