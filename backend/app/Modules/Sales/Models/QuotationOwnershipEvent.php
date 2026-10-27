<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * One append-only step of a quotation family's accountability chain: its first owner (INITIAL, or a CAPTURED_BASELINE adopted from an approved mapping), a
 * reassignment, or a change of the reserved access unit. Old and new values, actor, reason and the database's own time; the family always equals the latest.
 *
 * @property int $family_id
 * @property int $generation
 * @property int|null $previous_event_id
 * @property string $kind
 * @property int|null $old_owner_id
 * @property int $new_owner_id
 * @property int|null $old_unit_id
 * @property int|null $new_unit_id
 * @property int $access_generation
 * @property string|null $baseline_source_reference
 * @property string|null $approval_reference
 * @property int $actor_id
 * @property string $reason
 * @property Carbon $recorded_at
 */
class QuotationOwnershipEvent extends Model
{
    use BelongsToCompany;

    public const INITIAL = 'INITIAL';

    public const REASSIGNED = 'REASSIGNED';

    public const UNIT_CHANGED = 'UNIT_CHANGED';

    public const CAPTURED_BASELINE = 'CAPTURED_BASELINE';

    public $timestamps = false;

    protected $table = 'quotation_ownership_events';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }
}
