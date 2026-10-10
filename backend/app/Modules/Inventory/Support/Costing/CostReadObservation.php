<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support\Costing;

use Illuminate\Support\Facades\DB;

/** Internal read-only snapshot lease. Closing an owned snapshot releases it without writing. */
final readonly class CostReadObservation
{
    public function __construct(public CostReadSession $session, private bool $ownsTransaction) {}

    public function close(): void
    {
        if ($this->ownsTransaction) {
            DB::rollBack();
        }
    }
}
