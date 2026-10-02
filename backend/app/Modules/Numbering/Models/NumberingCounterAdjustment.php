<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * A manager raised a counter (ADR 0079 §16). Append-only; a counter can only go up, also enforced by a database check.
 *
 * @property int $counter_id
 * @property int $from_value
 * @property int $to_value
 * @property string $reason
 * @property int $changed_by
 */
class NumberingCounterAdjustment extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('A counter adjustment is history and cannot be edited.'));
        static::deleting(fn () => throw new DomainInvariantException('A counter adjustment is history and cannot be deleted.'));
    }
}
