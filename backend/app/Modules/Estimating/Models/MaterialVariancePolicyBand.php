<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Models;

use App\Models\Model;
use App\Models\Uom;
use App\Modules\Estimating\Support\VarianceDirection;
use App\Modules\Estimating\Support\VarianceEffect;
use App\Modules\Estimating\Support\VarianceMeasure;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One typed condition of a variance policy: a difference in `direction`, measured as `measure`,
 * at or beyond `threshold`, has `effect`. A QUANTITY band names its own unit (`uom_id`).
 *
 * @property int $policy_id
 * @property VarianceDirection $direction
 * @property VarianceMeasure $measure
 * @property string $threshold
 * @property int|null $uom_id
 * @property VarianceEffect $effect
 * @property-read Uom|null $uom
 */
class MaterialVariancePolicyBand extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => VarianceDirection::class,
            'measure' => VarianceMeasure::class,
            'effect' => VarianceEffect::class,
            'threshold' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<MaterialVariancePolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(MaterialVariancePolicy::class, 'policy_id');
    }

    /** @return BelongsTo<Uom, $this> */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }
}
