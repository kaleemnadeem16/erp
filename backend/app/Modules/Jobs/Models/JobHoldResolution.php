<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * The named resolution of exactly one JobHold, recorded by its RESOLVE_HOLD event. History; it cannot clear any other hold.
 *
 * @property int $id
 * @property int $job_id
 * @property int $event_id
 * @property int $hold_id
 * @property Carbon $resolved_effective_at
 * @property int $actor_id
 * @property string $reason
 * @property Carbon $recorded_at
 */
class JobHoldResolution extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'job_hold_resolutions';

    protected $guarded = ['id'];

    protected $casts = ['resolved_effective_at' => 'datetime', 'recorded_at' => 'datetime'];
}
