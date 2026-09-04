<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Modules\Inventory\Support\RecalculationApprovalDecision;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Support\Carbon;

/**
 * @property int $impact_version_id
 * @property RecalculationApprovalDecision $decision
 * @property string $notes
 * @property int $decided_by
 * @property Carbon $decided_at
 * @property Carbon|null $expires_at
 */
class RecalculationApproval extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Recalculation approvals are immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Recalculation approvals cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['impact_version_id' => RecalculationImpactVersion::class, 'decided_by' => User::class];
    }

    protected function casts(): array
    {
        return ['decision' => RecalculationApprovalDecision::class, 'decided_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
