<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable count attempt (the first count, or a later recount) for one
 * PhysicalCountLine. The line's own counted_qty/counted_by/counted_at always
 * mirror the latest entry; this table is the append-only audit trail a
 * recount would otherwise overwrite.
 *
 * @property int $physical_count_line_id
 * @property string $counted_qty
 * @property int $counted_by
 */
class PhysicalCountLineEntry extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Physical count line entries are immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Physical count line entries cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['physical_count_line_id' => PhysicalCountLine::class, 'counted_by' => User::class];
    }

    protected function casts(): array
    {
        return ['counted_qty' => 'decimal:4', 'counted_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<PhysicalCountLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(PhysicalCountLine::class, 'physical_count_line_id');
    }
}
