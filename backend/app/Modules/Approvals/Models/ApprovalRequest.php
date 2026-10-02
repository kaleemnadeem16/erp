<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Models;

use App\Models\Model;
use App\Models\User;
use App\Modules\Approvals\Support\ApprovalRequestStatus;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One routed approval of one document (ADR 0070). Its routing facts, hash and currency evidence are frozen at
 * submission; the policy version it was routed under stays explainable forever.
 *
 * @property string $subject_type
 * @property int $subject_id
 * @property int $policy_id
 * @property ApprovalRequestStatus $status
 * @property int $submitted_by
 * @property Carbon $submitted_at
 * @property Carbon|null $closed_at
 * @property array<string, mixed> $facts
 * @property string $facts_hash
 * @property array<string, mixed> $summary
 * @property string|null $closing_reason
 * @property-read Collection<int, ApprovalRequestStep> $steps
 * @property-read Collection<int, ApprovalDecision> $decisions
 */
class ApprovalRequest extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    /** @return array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    protected static function companyScopedForeignKeys(): array
    {
        return ['submitted_by' => User::class];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ApprovalRequestStatus::class,
            'submitted_at' => 'datetime',
            'closed_at' => 'datetime',
            'facts' => 'array',
            'summary' => 'array',
        ];
    }

    /** @return BelongsTo<ApprovalPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(ApprovalPolicy::class, 'policy_id');
    }

    /** @return HasMany<ApprovalRequestStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalRequestStep::class, 'request_id')->orderBy('stage')->orderBy('id');
    }

    /** @return HasMany<ApprovalDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class, 'request_id')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function isOpen(): bool
    {
        return $this->status === ApprovalRequestStatus::Pending;
    }
}
