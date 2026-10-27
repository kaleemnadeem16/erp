<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * One independent hold on a Job, placed by its HOLD event. History; resolving it is a separate JobHoldResolution, never an update of this row.
 *
 * @property int $id
 * @property int $job_id
 * @property int $event_id
 * @property string $scope_kind
 * @property string $reason
 * @property Carbon $effective_from
 * @property int $actor_id
 * @property Carbon $recorded_at
 */
class JobHold extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'job_holds';

    protected $guarded = ['id'];

    protected $casts = ['effective_from' => 'datetime', 'recorded_at' => 'datetime'];
}
