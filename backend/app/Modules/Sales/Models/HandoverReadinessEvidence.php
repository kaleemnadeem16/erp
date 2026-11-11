<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * Immutable service handover preparation; no target creation or release.
 *
 * @property int $stage_id
 * @property array<string,mixed> $assessment
 */
class HandoverReadinessEvidence extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'handover_readiness_evidence';

    protected $guarded = ['id'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['assessment' => 'array'];
    }
}
