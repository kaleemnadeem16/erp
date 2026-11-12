<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * A Job's activity. The basic workflow has exactly one DEFAULT activity per Job. History; the database refuses updates and deletes.
 *
 * @property int $id
 * @property int $job_id
 * @property string $kind
 * @property string $description
 * @property Carbon $recorded_at
 */
class JobActivity extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'job_activities';

    protected $guarded = ['id'];

    protected $casts = ['recorded_at' => 'datetime'];
}
