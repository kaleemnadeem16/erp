<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * The enquiry as received (ADR 0113): ORIGINAL_INTAKE for a lead recorded after the correction rollout, or CAPTURED_BASELINE — actual capture time and
 * a rollout reference, never backdated — for a lead that already existed. Immutable; the database refuses updates and deletes.
 *
 * @property int $id
 * @property int $lead_id
 * @property string $evidence_kind
 * @property Carbon $captured_at
 * @property int|null $captured_by
 * @property string|null $capture_reference
 * @property Carbon $received_at
 * @property int $recorded_creator_id
 * @property string $enquirer_name
 * @property string|null $enquirer_organisation
 * @property string|null $enquirer_email
 * @property string|null $enquirer_phone
 * @property string|null $description
 * @property string|null $source
 */
class LeadIntake extends Model
{
    use BelongsToCompany;

    public const ORIGINAL = 'ORIGINAL_INTAKE';

    public const BASELINE = 'CAPTURED_BASELINE';

    public $timestamps = false;

    protected $table = 'sales_lead_intakes';

    protected $guarded = ['id'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['captured_at' => 'datetime', 'received_at' => 'datetime'];
    }
}
