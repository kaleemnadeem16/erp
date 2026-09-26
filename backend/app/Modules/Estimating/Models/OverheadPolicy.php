<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Models;

use App\Models\Model;
use App\Models\User;
use App\Modules\Estimating\Support\OverheadDriverType;
use App\Modules\Estimating\Support\OverheadPolicyStatus;
use App\Modules\Estimating\Support\OverheadScopeType;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One version of a scope's overhead rule — see docs/domain/assemblies.md
 * "Overhead allocation" and ADR 0049. `scope_id` carries no database-level
 * FK (it targets assembly_groups or assemblies depending on scope_type,
 * or is NULL for COMPANY) — validated at the request layer instead, the
 * same "untyped polymorphic reference" ADR 0048 always described.
 *
 * @property OverheadScopeType $scope_type
 * @property int|null $scope_id
 * @property OverheadDriverType $driver_type
 * @property string $rate
 * @property string $reason
 * @property OverheadPolicyStatus $status
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property int $changed_by
 * @property-read User $changedBy
 */
class OverheadPolicy extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    /**
     * @return array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    protected static function companyScopedForeignKeys(): array
    {
        return ['changed_by' => User::class];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_type' => OverheadScopeType::class,
            'driver_type' => OverheadDriverType::class,
            'status' => OverheadPolicyStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * @param  Builder<OverheadPolicy>  $query
     * @return Builder<OverheadPolicy>
     */
    public function scopeForScope(Builder $query, OverheadScopeType $scopeType, ?int $scopeId): Builder
    {
        return $query->where('scope_type', $scopeType)
            ->when($scopeId === null, fn (Builder $q) => $q->whereNull('scope_id'), fn (Builder $q) => $q->where('scope_id', $scopeId));
    }
}
