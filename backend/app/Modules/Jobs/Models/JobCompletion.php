<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * The one immutable completion of a Job, written with its COMPLETE event: who completed it, when, their statement, and the ORIGINAL customer-agreed work it attests
 * (baseline, Sales release, agreed-conditions identity and fingerprint). History; the database refuses updates and deletes.
 *
 * @property int $id
 * @property int $job_id
 * @property int $event_id
 * @property int $baseline_id
 * @property int $release_event_id
 * @property string $condition_identity
 * @property string $baseline_fingerprint
 * @property string $statement
 * @property int $completed_by
 * @property Carbon|null $occurred_at
 * @property string $occurrence_precision
 * @property Carbon $recorded_at
 */
class JobCompletion extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'job_completions';

    protected $guarded = ['id'];

    protected $casts = ['occurred_at' => 'datetime', 'recorded_at' => 'datetime'];
}
