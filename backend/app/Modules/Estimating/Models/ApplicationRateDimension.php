<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tags one ApplicationRate as applying to one RateDimensionValue. A rate's
 * full set of these rows is the combination it resolves for — see
 * docs/domain/application-rates.md "Resolution". Managed only through
 * ApplicationRate's own create/update Actions, never directly; there is no
 * dedicated controller for this table.
 *
 * @property int $application_rate_id
 * @property int $rate_dimension_value_id
 */
class ApplicationRateDimension extends Model
{
    use BelongsToCompanyThroughParent;

    protected $table = 'application_rate_dimensions';

    protected $guarded = ['id'];

    /** @return BelongsTo<ApplicationRate, $this> */
    public function applicationRate(): BelongsTo
    {
        return $this->belongsTo(ApplicationRate::class);
    }

    /** @return BelongsTo<RateDimensionValue, $this> */
    public function rateDimensionValue(): BelongsTo
    {
        return $this->belongsTo(RateDimensionValue::class);
    }

    protected static function companyScopeRelation(): string
    {
        return 'applicationRate';
    }
}
