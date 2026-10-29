<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * One append-only observation of what a customer said or sent, with truthful occurrence precision (CA0a, disabled storage). An observation is never consent: nothing here is
 * an acceptance, a claim or an amount. Versions chain through `predecessor_id`, one successor each; an unknown occurrence or representative stays NULL / UNKNOWN.
 *
 * @property int $case_id
 * @property int|null $predecessor_id
 * @property string $source_kind
 * @property string|null $external_reference
 * @property string|null $source_version
 * @property string|null $customer_representative_snapshot
 * @property string $authority_verification_basis
 * @property Carbon|null $occurred_at
 * @property Carbon|null $occurred_until
 * @property string $occurrence_precision
 * @property string|null $source_timezone
 * @property string $factual_description
 * @property string $observation_state
 * @property int $recorded_by
 * @property Carbon $recorded_at
 */
class CustomerDecisionEvidence extends Model
{
    use BelongsToCompany;

    public const OBSERVED = 'OBSERVED';

    public $timestamps = false;

    protected $table = 'customer_decision_evidence';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'occurred_until' => 'datetime', 'recorded_at' => 'datetime'];
    }
}
