<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Modules\Inventory\Support\NegativeStockApprovalDecision;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Support\Carbon;

/**
 * @property NegativeStockApprovalDecision $decision
 * @property int $decided_by
 * @property Carbon $decided_at
 * @property string $notes
 */
class NegativeStockOverrideApproval extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Override approvals are immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Override approvals cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['request_id' => NegativeStockOverrideRequest::class, 'decided_by' => User::class];
    }

    protected function casts(): array
    {
        return ['decision' => NegativeStockApprovalDecision::class, 'decided_at' => 'immutable_datetime'];
    }
}
