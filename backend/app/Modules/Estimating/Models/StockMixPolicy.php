<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Modules\Estimating\Support\MaterialSelectionPolicyStatus;
use App\Modules\Estimating\Support\MaterialSelectionScope;
use App\Modules\Estimating\Support\StockMixLevel;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded choice of how tightly a scope controls hand-chosen stock (ADR 0076). Rows are never edited or deleted: a
 * change closes the active row (status and effective_to only) and inserts the next, so the history is the table.
 *
 * @property MaterialSelectionScope $scope_type
 * @property int|null $scope_id
 * @property StockMixLevel|null $level NULL = no preference at this scope, inherit
 * @property string|null $threshold_percent
 * @property string $reason
 * @property MaterialSelectionPolicyStatus $status
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property int $changed_by
 * @property string|null $scope_name Set by the controller for display only; not a column
 * @property-read User $changedBy
 */
class StockMixPolicy extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            if (array_diff(array_keys($row->getDirty()), ['status', 'effective_to', 'updated_at']) !== []) {
                throw new DomainInvariantException('A recorded stock-choice control is history: supersede it with a new one instead of editing it.');
            }
        });
        static::deleting(fn () => throw new DomainInvariantException('Stock-choice control history cannot be deleted.'));
    }

    /** @return array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    protected static function companyScopedForeignKeys(): array
    {
        return ['changed_by' => User::class];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scope_type' => MaterialSelectionScope::class,
            'level' => StockMixLevel::class,
            'threshold_percent' => 'decimal:4',
            'status' => MaterialSelectionPolicyStatus::class,
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
