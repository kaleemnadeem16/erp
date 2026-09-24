<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Models;

use App\Models\Model;
use App\Models\Uom;
use App\Modules\Estimating\Support\AssemblyComponentKind;
use App\Modules\Inventory\Models\Item;
use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an AssemblyVersion's recipe — see docs/domain/assemblies.md
 * "Schema" and "Cost roll-up" for exactly how each field is used, which
 * differs by kind:
 *
 *   MATERIAL     — item_id (+ optional formulation_id for nested pricing,
 *                  + optional application_rate_id for a live qty/uom
 *                  pointer), qty_per_work_unit, wastage_pct and multiplier
 *                  all apply
 *   LABOUR/PLANT — item_id (a LABOUR/PLANT item), qty_per_work_unit is
 *                  hours-per-work-unit, wastage_pct/multiplier do not apply
 *                  (see AssembleCostBreakdown — the formula never reads
 *                  them for these kinds even if non-default values were
 *                  somehow stored)
 *   SUBCONTRACT  — no item_id/formulation_id/application_rate_id;
 *                  rate_override is the component's only value, always
 *                  entered directly, qty_per_work_unit fixed at 1
 *
 * Two hops from company_id (assembly_components -> assembly_versions ->
 * assemblies) — BelongsToCompanyThroughParent composes through
 * AssemblyVersion, which already composes through Assembly. FK columns
 * carry no database-level company constraint (same as FormulationLine);
 * company scoping for item_id/formulation_id/application_rate_id/uom_id is
 * enforced at the request layer instead.
 *
 * @property int $assembly_version_id
 * @property AssemblyComponentKind $kind
 * @property int|null $item_id
 * @property int|null $formulation_id
 * @property int|null $application_rate_id
 * @property string $qty_per_work_unit
 * @property int $uom_id
 * @property string $wastage_pct
 * @property string $multiplier
 * @property string|null $rate_override
 * @property int $sequence
 * @property-read AssemblyVersion $assemblyVersion
 * @property-read Item|null $item
 * @property-read Formulation|null $formulation
 * @property-read ApplicationRate|null $applicationRate
 * @property-read Uom $uom
 */
class AssemblyComponent extends Model
{
    use BelongsToCompanyThroughParent;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AssemblyComponentKind::class,
            'qty_per_work_unit' => 'decimal:6',
            'wastage_pct' => 'decimal:4',
            'multiplier' => 'decimal:4',
            'rate_override' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<AssemblyVersion, $this> */
    public function assemblyVersion(): BelongsTo
    {
        return $this->belongsTo(AssemblyVersion::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Formulation, $this> */
    public function formulation(): BelongsTo
    {
        return $this->belongsTo(Formulation::class);
    }

    /** @return BelongsTo<ApplicationRate, $this> */
    public function applicationRate(): BelongsTo
    {
        return $this->belongsTo(ApplicationRate::class);
    }

    /** @return BelongsTo<Uom, $this> */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    protected static function companyScopeRelation(): string
    {
        return 'assemblyVersion';
    }
}
