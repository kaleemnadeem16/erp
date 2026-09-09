<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Backdating;

use App\Modules\Inventory\Support\BackdatingHorizonMode;
use Illuminate\Support\Carbon;

/**
 * The result of evaluating a proposed valuation-effective date against a
 * company's ADR 0040 backdating horizon. permittedFloor is the earliest
 * valuation-effective date the horizon allows — except under
 * BackdatingHorizonMode::PeriodLockOnly, where eligibility depends on the
 * proposed date's own accounting-period lock state rather than a single
 * calendar cutoff, so permittedFloor is null there unless a committed
 * inventory cutover supplies one; callers must branch on withinHorizon, not
 * on permittedFloor being set.
 */
final readonly class HorizonBoundary
{
    public function __construct(
        public bool $withinHorizon,
        public ?Carbon $permittedFloor,
        public BackdatingHorizonMode $mode,
    ) {}
}
