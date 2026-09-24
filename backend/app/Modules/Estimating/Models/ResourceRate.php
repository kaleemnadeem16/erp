<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Models;

use App\Models\Model;
use App\Models\Uom;
use App\Modules\Inventory\Models\Item;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The LABOUR/PLANT estimating-rate source — see docs/domain/assemblies.md
 * "Resource rates". A market fact, not a governed policy: no reason/audit
 * trail, no DRAFT/ACTIVE/SUPERSEDED lifecycle. A correction is a new row
 * with a later effective_from; there is deliberately no update Action.
 *
 * @property int $item_id
 * @property string $rate
 * @property int $rate_uom_id
 * @property Carbon $effective_from
 * @property-read Item $item
 * @property-read Uom $rateUom
 */
class ResourceRate extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    /**
     * @return array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    protected static function companyScopedForeignKeys(): array
    {
        return ['item_id' => Item::class, 'rate_uom_id' => Uom::class];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'effective_from' => 'date',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Uom, $this> */
    public function rateUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'rate_uom_id');
    }
}
