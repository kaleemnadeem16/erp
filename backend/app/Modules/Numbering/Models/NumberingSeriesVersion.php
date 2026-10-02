<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Modules\Numbering\Support\Continuity;
use App\Modules\Numbering\Support\CounterScope;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * The governed configuration of a series at a time: template + counter scope + continuity (ADR 0079 §11). Never edited or
 * deleted — a change closes the active version (status and effective_to only) and inserts the next.
 *
 * @property int $series_id
 * @property string $template
 * @property string $scope_period
 * @property list<string> $scope_dimensions
 * @property Continuity $continuity
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string $status
 * @property string $reason
 * @property int|null $changed_by
 */
class NumberingSeriesVersion extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            if (array_diff(array_keys($row->getDirty()), ['status', 'effective_to', 'updated_at']) !== []) {
                throw new DomainInvariantException('A numbering version is history: add a new version instead of editing it.');
            }
        });
        static::deleting(fn () => throw new DomainInvariantException('Numbering versions cannot be deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['continuity' => Continuity::class, 'scope_dimensions' => 'array', 'effective_from' => 'datetime', 'effective_to' => 'datetime'];
    }

    public function scope(): CounterScope
    {
        return CounterScope::fromArray(['period' => $this->scope_period, 'dimensions' => $this->scope_dimensions ?? []]);
    }
}
