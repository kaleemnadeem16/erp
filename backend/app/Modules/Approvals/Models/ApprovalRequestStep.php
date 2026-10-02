<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Models;

use App\Models\Model;
use App\Modules\Approvals\Support\ApprovalStepStatus;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $request_id
 * @property int $policy_step_id
 * @property int $stage
 * @property string $name
 * @property int $required_approvals
 * @property ApprovalStepStatus $status
 * @property Carbon|null $opened_at
 * @property Carbon|null $deadline_at
 * @property Carbon|null $overdue_at
 * @property Carbon|null $completed_at
 */
class ApprovalRequestStep extends Model
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
            'status' => ApprovalStepStatus::class,
            'opened_at' => 'datetime',
            'deadline_at' => 'datetime',
            'overdue_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ApprovalPolicyStep, $this> */
    public function policyStep(): BelongsTo
    {
        return $this->belongsTo(ApprovalPolicyStep::class, 'policy_step_id');
    }

    /** @return BelongsTo<ApprovalRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }

    /** @return HasMany<ApprovalDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class, 'request_step_id')->orderBy('id');
    }
}
