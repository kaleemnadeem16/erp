<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The stable business quotation: its revisions, logical scopes and choice groups hang from it. It carries the one current CRM association (optional) and the
 * generations the database advances; every association change is an append-only event the database requires. It never holds an official number.
 *
 * @property int $id
 * @property int $revision_generation
 * @property int|null $current_opportunity_id
 * @property int $association_generation
 * @property int $created_by
 * @property int|null $responsible_owner_id
 * @property int|null $access_unit_id
 * @property int $ownership_generation
 * @property int $access_generation
 * @property string $access_baseline_state
 */
class QuotationFamily extends Model
{
    use BelongsToCompany;

    protected $table = 'quotation_families';

    protected $guarded = ['id'];

    /** @return HasMany<QuotationRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(QuotationRevision::class, 'family_id')->orderBy('revision_ordinal');
    }

    /** @return HasMany<QuotationFamilyAssociationEvent, $this> */
    public function associationEvents(): HasMany
    {
        return $this->hasMany(QuotationFamilyAssociationEvent::class, 'family_id')->orderBy('generation');
    }

    /** @return HasMany<QuotationOwnershipEvent, $this> */
    public function ownershipEvents(): HasMany
    {
        return $this->hasMany(QuotationOwnershipEvent::class, 'family_id')->orderBy('generation');
    }

    /** @return BelongsTo<Opportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'current_opportunity_id');
    }
}
