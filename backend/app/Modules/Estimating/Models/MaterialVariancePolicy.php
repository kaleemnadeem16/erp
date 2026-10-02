<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Models;

use App\Models\Model;
use App\Models\User;
use App\Modules\Estimating\Support\MaterialVariancePolicyStatus;
use App\Modules\Estimating\Support\VarianceEffect;
use App\Modules\Estimating\Support\VarianceScopeType;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One version of a scope's material-variance rule (ADR 0054). The row id is the "policy version"
 * an evaluation snapshots, so a later edit never reinterprets history: a new version is created and
 * activated, and the old one becomes SUPERSEDED. `scope_id` targets categories or items depending
 * on `scope_type` (NULL for COMPANY) and has no foreign key — validated at the request layer.
 *
 * @property VarianceScopeType $scope_type
 * @property int|null $scope_id
 * @property VarianceEffect|null $minimum_effect
 * @property string $reason
 * @property MaterialVariancePolicyStatus $status
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property int $changed_by
 * @property-read Collection<int, MaterialVariancePolicyBand> $bands
 */
class MaterialVariancePolicy extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    /** @return array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    protected static function companyScopedForeignKeys(): array
    {
        return ['changed_by' => User::class];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scope_type' => VarianceScopeType::class,
            'minimum_effect' => VarianceEffect::class,
            'status' => MaterialVariancePolicyStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /** @return HasMany<MaterialVariancePolicyBand, $this> */
    public function bands(): HasMany
    {
        return $this->hasMany(MaterialVariancePolicyBand::class, 'policy_id')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * @param  Builder<MaterialVariancePolicy>  $query
     * @return Builder<MaterialVariancePolicy>
     */
    public function scopeForScope(Builder $query, VarianceScopeType $scopeType, ?int $scopeId): Builder
    {
        return $query->where('scope_type', $scopeType)
            ->when($scopeId === null, fn (Builder $q) => $q->whereNull('scope_id'), fn (Builder $q) => $q->where('scope_id', $scopeId));
    }
}
