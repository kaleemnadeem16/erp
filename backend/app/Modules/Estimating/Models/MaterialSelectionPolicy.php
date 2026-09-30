<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Modules\Estimating\Support\MaterialSelectionPolicyStatus;
use App\Modules\Estimating\Support\MaterialSelectionScope;
use App\Modules\Estimating\Support\MaterialSelectionStrategy;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded choice of selection strategy for a scope (ADR 0071). Rows are
 * never edited or deleted: a change closes the active row (status and
 * effective_to only) and inserts the next one, so the history is the table.
 *
 * @property MaterialSelectionScope $scope_type
 * @property int|null $scope_id
 * @property MaterialSelectionStrategy|null $strategy NULL = no preference at this scope, inherit
 * @property string $reason
 * @property MaterialSelectionPolicyStatus $status
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property int $changed_by
 * @property string|null $scope_name Set by the controller for display only; not a column
 * @property-read User $changedBy
 */
class MaterialSelectionPolicy extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            $changed = array_keys($row->getDirty());
            if (array_diff($changed, ['status', 'effective_to', 'updated_at']) !== []) {
                throw new DomainInvariantException('A recorded selection strategy is history: supersede it with a new one instead of editing it.');
            }
        });
        static::deleting(fn () => throw new DomainInvariantException('Selection strategy history cannot be deleted.'));
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
            'strategy' => MaterialSelectionStrategy::class,
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
