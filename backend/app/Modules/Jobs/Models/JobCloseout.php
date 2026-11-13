<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * The one immutable operational closeout of a Job, written with its CLOSE event on a READY assessment. It settles no invoice or balance. History.
 *
 * @property int $id
 * @property int $job_id
 * @property int $event_id
 * @property int $assessment_id
 * @property string|null $statement
 * @property int $closed_by
 * @property Carbon|null $occurred_at
 * @property string $occurrence_precision
 * @property Carbon $recorded_at
 */
class JobCloseout extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'job_closeouts';

    protected $guarded = ['id'];

    protected $casts = ['occurred_at' => 'datetime', 'recorded_at' => 'datetime'];
}
