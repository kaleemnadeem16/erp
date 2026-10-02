<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * The permanent record of one number issued (ADR 0079 §12): the final text, the series and version that produced it, every
 * resolved variable with the record it came from, the instant and local date used, the counter scope key and value.
 * Append-only; a number is never recomputed.
 *
 * @property string $doc_type
 * @property int $series_id
 * @property int $version_id
 * @property int|null $document_id
 * @property string $number
 * @property string $counter_key
 * @property int $counter_value
 * @property Carbon $issued_at
 * @property Carbon|null $local_date
 * @property array<string, array{value: string, source: string|null}> $variables
 * @property int|null $allocated_by
 */
class NumberingAllocation extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            // The document id is learned only after the document row exists; filling it once is the one permitted change.
            if (array_diff(array_keys($row->getDirty()), ['document_id', 'updated_at']) !== [] || $row->getOriginal('document_id') !== null) {
                throw new DomainInvariantException('An issued number is history and cannot be edited.');
            }
        });
        static::deleting(fn () => throw new DomainInvariantException('An issued number is history and cannot be deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'local_date' => 'date', 'variables' => 'array'];
    }
}
