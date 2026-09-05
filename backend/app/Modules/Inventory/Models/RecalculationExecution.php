<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One immutable execution fact for a RecalculationRequest — see
 * docs/domain/stock-recalculation.md. The Inventory-owned slice of ADR
 * 0040's APPEND_ONLY_LINKED_CORRECTION_PACKAGE: every case this codebase can
 * execute today is "remaining stock," since Sales/Job Execution/Accounting
 * — the other potential owners of a correction delta — don't exist yet. One
 * row per request (DB-unique); a retry resolves to the same row rather than
 * executing twice.
 *
 * @property int $recalculation_request_id
 * @property int $impact_version_id
 * @property int $stock_move_id
 * @property int $executed_by
 * @property Carbon $executed_at
 */
class RecalculationExecution extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Recalculation executions are immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Recalculation executions cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return [
            'recalculation_request_id' => RecalculationRequest::class,
            'impact_version_id' => RecalculationImpactVersion::class,
            'stock_move_id' => StockMove::class,
            'executed_by' => User::class,
        ];
    }

    protected function casts(): array
    {
        return ['executed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<RecalculationRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(RecalculationRequest::class, 'recalculation_request_id');
    }
}
