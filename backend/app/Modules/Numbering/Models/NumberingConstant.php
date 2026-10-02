<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * A company-defined constant usable as {NAME} in a template (ADR 0079 §13). A governed history: a row per change; a NULL value
 * retires the constant.
 *
 * @property string $code
 * @property string|null $value
 * @property string $status
 * @property string $reason
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property int $changed_by
 */
class NumberingConstant extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            if (array_diff(array_keys($row->getDirty()), ['status', 'effective_to', 'updated_at']) !== []) {
                throw new DomainInvariantException('A recorded constant is history: record a new value instead of editing it.');
            }
        });
        static::deleting(fn () => throw new DomainInvariantException('Constant history cannot be deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_from' => 'datetime', 'effective_to' => 'datetime'];
    }
}
