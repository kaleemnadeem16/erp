<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One value on a RateDimension's axis — e.g. "Smooth plaster" on Surface
 * Type. Scoped through rateDimension, not its own company_id — see
 * docs/architecture/multi-tenancy.md "Child tables scope through their
 * parent".
 *
 * @property int $rate_dimension_id
 * @property string $code
 * @property string $name
 * @property int $sort_order
 */
class RateDimensionValue extends Model
{
    use BelongsToCompanyThroughParent;

    protected $guarded = ['id'];

    /** @return BelongsTo<RateDimension, $this> */
    public function rateDimension(): BelongsTo
    {
        return $this->belongsTo(RateDimension::class);
    }

    protected static function companyScopeRelation(): string
    {
        return 'rateDimension';
    }
}
