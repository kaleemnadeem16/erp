<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * One append-only change of a family's current CRM association (ADR 0099): old and new Opportunity (either may be absent), actor, time and, where the
 * governing rule requires one, a reason. The events form one unbroken chain and the family always equals the latest.
 *
 * @property int $family_id
 * @property int $generation
 * @property int|null $old_opportunity_id
 * @property int|null $new_opportunity_id
 * @property int $actor_id
 * @property Carbon $occurred_at
 * @property string|null $reason
 */
class QuotationFamilyAssociationEvent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'quotation_family_association_events';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
