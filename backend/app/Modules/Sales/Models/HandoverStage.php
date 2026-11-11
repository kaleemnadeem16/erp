<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * Immutable service handover preparation; no target creation or release.
 *
 * @property int $allocation_id
 * @property string $stage_key
 * @property string $target_kind
 * @property string $state
 */
class HandoverStage extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'handover_stages';

    protected $guarded = ['id'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [];
    }
}
