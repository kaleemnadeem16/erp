<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * One recorded change of a Job's state and generation. History; the database refuses updates and deletes.
 *
 * @property int $id
 * @property int $job_id
 * @property int $sequence
 * @property string $operation
 * @property string|null $from_state
 * @property string $to_state
 * @property int $prior_generation
 * @property int $next_generation
 * @property Carbon|null $occurred_at
 * @property string $occurrence_precision
 * @property Carbon $recorded_at
 * @property int $actor_id
 * @property string|null $reason
 * @property int $evidence_version
 * @property string|null $command_identity the command's stable identity (J1-F1); present together with the semantic intent hash
 * @property string|null $semantic_intent_hash
 */
class JobEvent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'job_events';

    protected $guarded = ['id'];

    protected $casts = ['occurred_at' => 'datetime', 'recorded_at' => 'datetime'];
}
