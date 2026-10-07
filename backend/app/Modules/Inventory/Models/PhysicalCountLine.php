<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $physical_count_id
 * @property int $item_id
 * @property int $location_id
 * @property int|null $batch_id
 * @property string|null $serial_no
 * @property string $book_qty
 * @property string|null $counted_qty
 * @property int|null $counted_by
 * @property Carbon|null $counted_at
 * @property string|null $found_unit_cost
 * @property int|null $posted_stock_move_id
 * @property bool $grouped
 * @property int|null $package_operation_id
 * @property int|null $package_watermark
 */
class PhysicalCountLine extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (PhysicalCountLine $line): void {
            $allowed = ['counted_qty', 'counted_by', 'counted_at', 'found_unit_cost', 'posted_stock_move_id', 'updated_at'];
            // A grouped line may be refreshed (ADR 0092): re-snapshotted against current evidence, never silently rebased.
            if ($line->grouped) {
                $allowed = array_merge($allowed, ['book_qty', 'package_operation_id', 'package_watermark']);
            }
            if (array_diff(array_keys($line->getDirty()), $allowed) !== []) {
                throw new DomainInvariantException('Physical count line identity is immutable.');
            }
        });
        static::deleting(fn () => throw new DomainInvariantException('Physical count lines cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return [
            'physical_count_id' => PhysicalCount::class, 'item_id' => Item::class, 'location_id' => Location::class,
            'batch_id' => StockBatch::class, 'counted_by' => User::class, 'posted_stock_move_id' => StockMove::class,
        ];
    }

    protected function casts(): array
    {
        return [
            'book_qty' => 'decimal:4', 'grouped' => 'boolean', 'counted_qty' => 'decimal:4', 'found_unit_cost' => 'decimal:4',
            'counted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PhysicalCount, $this> */
    public function count(): BelongsTo
    {
        return $this->belongsTo(PhysicalCount::class, 'physical_count_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<PhysicalCountLineEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(PhysicalCountLineEntry::class);
    }
}
