<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Models;

use App\Models\Model;
use App\Modules\Approvals\Support\ExpiryAction;
use App\Modules\Approvals\Support\Review\ReviewProfile;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;

/**
 * @property int $policy_id
 * @property ReviewProfile $review_profile
 * @property int $stage steps sharing a stage run in parallel
 * @property string $name
 * @property int $required_approvals distinct approvers needed
 * @property string|null $permission null = the subject type's own approve capability
 * @property bool $use_authority_limit
 * @property bool $allow_submitter
 * @property int|null $expires_after_hours
 * @property ExpiryAction $on_expiry
 * @property string|null $escalation_permission
 */
class ApprovalPolicyStep extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    /** @return array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    protected static function companyScopedForeignKeys(): array
    {
        return [];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'review_profile' => ReviewProfile::class,
            'use_authority_limit' => 'bool',
            'allow_submitter' => 'bool',
            'on_expiry' => ExpiryAction::class,
            'stage' => 'integer',
            'required_approvals' => 'integer',
        ];
    }
}
