<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * One step of the Job's responsibility chain: a company user is made responsible, with an optional intended window. History; the database refuses updates and deletes, a fork and a gap. Records responsibility only: it grants no access, start or accepted-scope change.
 *
 * @property int $id
 * @property int $job_id
 * @property int $event_id
 * @property int $sequence
 * @property int|null $predecessor_id
 * @property int $responsible_user_id
 * @property Carbon|null $intended_from
 * @property Carbon|null $intended_until
 * @property string|null $instructions
 * @property int $actor_id
 * @property Carbon|null $occurred_at
 * @property string $occurrence_precision
 * @property string|null $reason
 * @property Carbon $recorded_at
 */
class JobAssignmentEvent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'job_assignment_events';

    protected $guarded = ['id'];

    protected $casts = ['intended_from' => 'datetime', 'intended_until' => 'datetime', 'occurred_at' => 'datetime', 'recorded_at' => 'datetime'];
}
