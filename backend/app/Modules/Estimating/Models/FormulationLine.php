<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Models;

use App\Models\Model;
use App\Models\Uom;
use App\Modules\Inventory\Models\Item;
use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ingredient line on a FormulationVersion — how much of input_item_id
 * per formulation.output_qty of output. wastage_pct is material lost in
 * process, applied on top of qty. Scoped through formulationVersion, two
 * hops from company_id (formulation_lines -> formulation_versions ->
 * formulations) — BelongsToCompanyThroughParent composes through another
 * model using the same trait, see that trait's own docblock.
 *
 * @property int $formulation_version_id
 * @property int $input_item_id
 * @property string $qty
 * @property int $uom_id
 * @property string $wastage_pct
 * @property int $sequence
 * @property-read FormulationVersion $formulationVersion
 * @property-read Item $inputItem
 * @property-read Uom $uom
 */
class FormulationLine extends Model
{
    use BelongsToCompanyThroughParent;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['qty' => 'decimal:6', 'wastage_pct' => 'decimal:4'];
    }

    /** @return BelongsTo<FormulationVersion, $this> */
    public function formulationVersion(): BelongsTo
    {
        return $this->belongsTo(FormulationVersion::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function inputItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'input_item_id');
    }

    /** @return BelongsTo<Uom, $this> */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    protected static function companyScopeRelation(): string
    {
        return 'formulationVersion';
    }
}
