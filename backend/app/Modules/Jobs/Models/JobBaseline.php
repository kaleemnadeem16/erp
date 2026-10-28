<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * A Job's declared scope basis, versioned and chained by predecessor. History; the database refuses updates and deletes.
 *
 * @property int $id
 * @property int $job_id
 * @property int $version
 * @property int|null $predecessor_id
 * @property string $fingerprint
 * @property string $scope_description
 * @property string $completion_rule_version
 * @property Carbon $recorded_at
 */
class JobBaseline extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'job_baselines';

    protected $guarded = ['id'];

    protected $casts = ['recorded_at' => 'datetime'];
}
