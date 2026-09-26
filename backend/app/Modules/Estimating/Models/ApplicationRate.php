<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\Uom;
use App\Modules\Inventory\Models\Item;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * How much of item_id is consumed per one work_uom_id — e.g. 0.0833 L per
 * sqm. Stored as consumption per work unit, never coverage, even though
 * users think in coverage — see docs/domain/application-rates.md
 * "Direction". Tagged with zero or more RateDimensionValue rows through
 * application_rate_dimensions; the resolver
 * (App\Modules\Estimating\Actions\ResolveApplicationRate) is what turns a
 * document's dimension values into a winning rate.
 *
 * @property int $item_id
 * @property int $work_uom_id
 * @property string $qty_per_work_unit
 * @property int $uom_id
 * @property bool $is_default
 * @property-read Item $item
 * @property-read Uom $workUom
 * @property-read Uom $uom
 */
class ApplicationRate extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (ApplicationRate $rate): void {
            if ($rate->isDirty(['item_id', 'work_uom_id'])) {
                throw new DomainInvariantException(
                    'An application rate cannot be repointed to a different item or work unit; archive it and create another.',
                    $rate->isDirty('item_id') ? 'item_id' : 'work_uom_id',
                );
            }
        });
    }

    /**
     * @return array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    protected static function companyScopedForeignKeys(): array
    {
        return [
            'item_id' => Item::class,
            'work_uom_id' => Uom::class,
            'uom_id' => Uom::class,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['qty_per_work_unit' => 'decimal:6', 'is_default' => 'bool'];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Uom, $this> */
    public function workUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'work_uom_id');
    }

    /** @return BelongsTo<Uom, $this> */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'uom_id');
    }

    /** @return BelongsToMany<RateDimensionValue, $this> */
    public function dimensionValues(): BelongsToMany
    {
        return $this->belongsToMany(RateDimensionValue::class, 'application_rate_dimensions');
    }
}
