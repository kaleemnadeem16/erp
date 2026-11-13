<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * An immutable READY or BLOCKED assessment of a Job's closeout at one generation. It never changes the Job; a close consumes a READY one of exactly the generation it closes.
 *
 * @property int $id
 * @property int $job_id
 * @property int $actor_id
 * @property string $request_key
 * @property string $intent_hash
 * @property int $at_generation
 * @property string $status
 * @property list<string> $blockers
 * @property string|null $source_pin
 * @property Carbon $recorded_at
 */
class JobCloseoutAssessment extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'job_closeout_assessments';

    protected $guarded = ['id'];

    protected $casts = ['blockers' => 'array', 'recorded_at' => 'datetime'];
}
