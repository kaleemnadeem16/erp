<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * The Sales lineage of a Job: the release event it was received from. One Job per release event, by company-composite keys. History; the database refuses updates and deletes.
 *
 * @property int $id
 * @property int $job_id
 * @property int $release_event_id
 * @property Carbon $recorded_at
 */
class JobSalesOrigin extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'job_sales_origins';

    protected $guarded = ['id'];

    protected $casts = ['recorded_at' => 'datetime'];
}
