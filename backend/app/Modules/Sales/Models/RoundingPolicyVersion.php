<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Models\User;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One version of the company's Sales rounding and unit-price precision policy (ADR 0120, 0144). A draft is created, then activated with an effective
 * date, which closes its predecessor; an activated version is immutable and permanent (the database enforces both). Read it through
 * `ResolveRoundingPolicy`, not directly — that is what knows the built-in default and the date semantics.
 *
 * @property int $id
 * @property int $version_no
 * @property int $unit_price_scale
 * @property string $rounding_mode
 * @property string $status
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_end_exclusive
 * @property int|null $previous_version_id
 * @property string $reason
 * @property int $changed_by
 * @property int|null $activated_by
 * @property Carbon|null $activated_at
 */
class RoundingPolicyVersion extends Model
{
    use BelongsToCompany;

    protected $table = 'sales_rounding_policy_versions';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_end_exclusive' => 'date', 'activated_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /** @return BelongsTo<self, $this> */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }
}
