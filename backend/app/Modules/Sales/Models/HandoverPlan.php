<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * Immutable service handover preparation; no target creation or release.
 *
 * @property int $acceptance_id
 * @property int $prepared_by
 * @property string $path
 * @property string $policy_version
 * @property string $intent_digest
 * @property string $preview_token
 * @property array<string,mixed> $inputs
 * @property Carbon $recorded_at
 */
class HandoverPlan extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'handover_plans';

    protected $guarded = ['id'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['inputs' => 'array', 'recorded_at' => 'datetime'];
    }
}
