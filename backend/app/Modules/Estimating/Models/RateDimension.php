<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company-defined axis application rates vary by — e.g. Surface Type,
 * Thickness. Values live on RateDimensionValue. Core code never special-cases
 * a dimension — see docs/domain/application-rates.md and
 * docs/decisions/0009-company-defined-rate-dimensions.md.
 *
 * @property string $code
 * @property string $name
 * @property int $sort_order
 * @property bool $is_required
 */
class RateDimension extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_required' => 'bool'];
    }

    /** @return HasMany<RateDimensionValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(RateDimensionValue::class)->orderBy('sort_order');
    }
}
