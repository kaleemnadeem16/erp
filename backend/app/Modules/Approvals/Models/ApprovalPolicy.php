<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Models;

use App\Models\Model;
use App\Models\User;
use App\Modules\Approvals\Support\ApprovalPolicyStatus;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One version of a named approval policy. The row id is the policy version a request snapshots, so a later edit never
 * reinterprets history: a new version is created and activated and the old one becomes SUPERSEDED.
 *
 * @property string $subject_type
 * @property string $code
 * @property int $version_no
 * @property string $name
 * @property int $priority
 * @property ApprovalPolicyStatus $status
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property string $reason
 * @property int $changed_by
 * @property-read Collection<int, ApprovalPolicyCondition> $conditions
 * @property-read Collection<int, ApprovalPolicyStep> $steps
 */
class ApprovalPolicy extends Model
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
            'status' => ApprovalPolicyStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'priority' => 'integer',
        ];
    }

    /** @return HasMany<ApprovalPolicyCondition, $this> */
    public function conditions(): HasMany
    {
        return $this->hasMany(ApprovalPolicyCondition::class, 'policy_id');
    }

    /** @return HasMany<ApprovalPolicyStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalPolicyStep::class, 'policy_id')->orderBy('stage')->orderBy('id');
    }

    /** @param  Builder<ApprovalPolicy>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', ApprovalPolicyStatus::Active);
    }
}
