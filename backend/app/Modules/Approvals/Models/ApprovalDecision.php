<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Models;

use App\Models\Model;
use App\Models\User;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An append-only fact (immutable at the database). `principal_id` is whose authority was used: the decider, or the
 * delegator when the decider acted under a delegation.
 *
 * @property int $request_id
 * @property int $request_step_id
 * @property ApprovalDecisionKind $decision
 * @property int $decided_by
 * @property int $principal_id
 * @property int|null $delegation_id
 * @property string|null $reason
 * @property array<string, mixed> $authority_snapshot
 * @property Carbon $decided_at
 */
class ApprovalDecision extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    /** @return array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    protected static function companyScopedForeignKeys(): array
    {
        return ['decided_by' => User::class, 'principal_id' => User::class];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'decision' => ApprovalDecisionKind::class,
            'authority_snapshot' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
