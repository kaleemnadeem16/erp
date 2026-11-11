<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * Immutable service handover preparation; no target creation or release.
 *
 * @property int $plan_id
 * @property int $acceptance_line_id
 * @property int $full_scope_units
 */
class HandoverAllocation extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'handover_allocations';

    protected $guarded = ['id'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [];
    }
}
