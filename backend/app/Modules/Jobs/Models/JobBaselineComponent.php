<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * The accepted deliverable and the exact agreed completion conditions a Job was released with, bound to its baseline, release event, allocation, issued line and
 * completion basis. History; the database refuses updates and deletes.
 *
 * @property int $id
 * @property int $job_id
 * @property int $baseline_id
 * @property int $release_event_id
 * @property int $allocation_id
 * @property int $issued_line_id
 * @property int $basis_id
 * @property string $deliverable_identity
 * @property string $deliverable_text
 * @property string $agreed_conditions
 * @property string $condition_identity
 * @property Carbon $recorded_at
 */
class JobBaselineComponent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'job_baseline_components';

    protected $guarded = ['id'];

    protected $casts = ['recorded_at' => 'datetime'];
}
