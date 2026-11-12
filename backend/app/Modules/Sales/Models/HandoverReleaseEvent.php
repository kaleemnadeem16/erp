<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * Immutable evidence that one prepared service stage was released to its Jobs receiver, with the completion-basis identity/version it was released under, the
 * release intent, the cost requirement and status (kept separate) and the source generations relied on. The effective release status of a stage is DERIVED from
 * the presence of this row; the preparation rows are never updated.
 *
 * @property int $stage_id
 * @property int $baseline_id
 * @property int $basis_id
 * @property string $purpose
 * @property int $actor_id
 * @property string $request_key
 * @property string $intent_hash
 * @property string $condition_identity
 * @property int $condition_version
 * @property bool $cost_required
 * @property string $cost_status
 * @property string $cost_policy_version
 * @property array<string,mixed> $relied_on
 * @property Carbon $released_at
 */
class HandoverReleaseEvent extends Model
{
    use BelongsToCompany;

    public const PURPOSE_SERVICE_JOB_RECEIPT = 'SERVICE_JOB_RECEIPT';

    public $timestamps = false;

    protected $table = 'handover_release_events';

    protected $guarded = ['id'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['cost_required' => 'boolean', 'relied_on' => 'array', 'released_at' => 'datetime'];
    }
}
