<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * FULL_ONLY customer acceptance storage. Inactive until the owner approves duty holders.
 *
 * @property int $case_id
 * @property int $decision_component_id
 * @property int $revision_id
 * @property int $consent_evidence_id
 * @property int $policy_version_id
 * @property int $actor_id
 * @property string $intent_digest
 * @property Carbon $recorded_at
 */
class CustomerAcceptance extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'customer_acceptances';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }
}
