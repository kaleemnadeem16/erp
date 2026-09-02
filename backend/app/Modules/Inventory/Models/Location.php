<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Modules\Inventory\Support\LocationType;
use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $warehouse_id
 * @property string $code
 * @property string $name
 * @property bool $active
 * @property int|null $parent_id
 * @property LocationType $type
 * @property string|null $barcode
 * @property int $sort_order
 * @property bool $allows_stock
 * @property-read Warehouse $warehouse
 */
class Location extends Model
{
    use BelongsToCompanyThroughParent;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (Location $location): void {
            if ($location->type === LocationType::View && $location->allows_stock) {
                throw new DomainInvariantException('A VIEW location cannot hold stock.', 'allows_stock');
            }

            if (! $location->isDirty('parent_id') || $location->parent_id === null) {
                return;
            }

            $parent = Location::query()->find($location->parent_id);
            if ($parent === null || $parent->warehouse_id !== $location->warehouse_id) {
                throw new DomainInvariantException(
                    'A parent location must belong to the same warehouse.',
                    'parent_id',
                );
            }

            $visited = [$location->getKey()];
            while ($parent !== null) {
                if (in_array($parent->getKey(), $visited, true)) {
                    throw new DomainInvariantException('A location hierarchy cannot contain a cycle.', 'parent_id');
                }
                $visited[] = $parent->getKey();
                $parent = $parent->parent;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'active' => 'bool',
            'type' => LocationType::class,
            'allows_stock' => 'bool',
        ];
    }

    protected static function companyScopeRelation(): string
    {
        return 'warehouse';
    }

    /** @return Attribute<string, string> */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $value) => strtolower($value));
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Location, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
