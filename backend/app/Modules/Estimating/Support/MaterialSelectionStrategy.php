<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * ADR 0053's three named, deterministic package-selection presets. Their
 * criteria are compared lexicographically — the first that differs decides,
 * the next only breaks a tie — never as a weighted score.
 *
 * The effective strategy for an item resolves item → nearest item-category
 * ancestor → company → the built-in default (ADR 0053 §2, stored by ADR 0071),
 * unless a per-request what-if override replaces it; the response says which
 * level supplied it (`BUILT_IN_DEFAULT`, `COMPANY`, `ITEM_CATEGORY`, `ITEM` or
 * `REQUEST_OVERRIDE`).
 */
enum MaterialSelectionStrategy: string
{
    case MinimiseWaste = 'MINIMISE_WASTE';
    case LowestInternalCost = 'LOWEST_INTERNAL_COST';
    case FewestPackages = 'FEWEST_PACKAGES';

    public static function companyDefault(): self
    {
        return self::MinimiseWaste;
    }

    public function label(): string
    {
        return match ($this) {
            self::MinimiseWaste => 'Minimise waste',
            self::LowestInternalCost => 'Lowest internal cost',
            self::FewestPackages => 'Fewest packages',
        };
    }

    /** @return list<AllocationCriterion> */
    public function criteria(): array
    {
        return match ($this) {
            self::MinimiseWaste => [
                AllocationCriterion::OpenRemnants,
                AllocationCriterion::EarliestExpiry,
                AllocationCriterion::LeastSurplus,
                AllocationCriterion::LowestInternalCost,
                AllocationCriterion::FewestPicks,
            ],
            self::LowestInternalCost => [
                AllocationCriterion::LowestInternalCost,
                AllocationCriterion::LeastSurplus,
                AllocationCriterion::EarliestExpiry,
                AllocationCriterion::FewestPicks,
            ],
            self::FewestPackages => [
                AllocationCriterion::FewestNewPackages,
                AllocationCriterion::FewestPicks,
                AllocationCriterion::LeastSurplus,
                AllocationCriterion::LowestInternalCost,
                AllocationCriterion::EarliestExpiry,
            ],
        };
    }

    /**
     * In QUANTITY_ONLY the only criteria that can be evaluated are cost and
     * picks (see AllocateInventory); this says which the preset compares first.
     */
    public function ranksCostBeforePicks(): bool
    {
        return $this !== self::FewestPackages;
    }
}
