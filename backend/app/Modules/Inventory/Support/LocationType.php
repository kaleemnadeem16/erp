<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum LocationType: string
{
    case Storage = 'STORAGE';
    case Receiving = 'RECEIVING';
    case Quarantine = 'QUARANTINE';
    case Dispatch = 'DISPATCH';
    case Returns = 'RETURNS';
    case Transit = 'TRANSIT';
    case Scrap = 'SCRAP';
    case Production = 'PRODUCTION';
    case View = 'VIEW';

    /**
     * Whether stock sitting in a location of this type counts as usable for
     * Estimating's advisory availability (docs/domain/inventory-packages.md,
     * "Hard filters"): general storage and the receiving dock. Quarantine,
     * scrap, transit, returns, dispatch staging and production WIP are
     * deliberately excluded — stock there is held, gone, in motion, awaiting
     * disposition or already committed. A conservative first-generation rule:
     * it may under-report usable stock (a shortage the user can see explained)
     * but never over-report it. The inventory overview's own "available"
     * figure is location-agnostic and does not apply this rule.
     */
    public function holdsUsableStock(): bool
    {
        return match ($this) {
            self::Storage, self::Receiving => true,
            default => false,
        };
    }
}
