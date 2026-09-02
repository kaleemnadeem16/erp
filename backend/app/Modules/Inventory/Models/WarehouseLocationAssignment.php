<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Modules\Inventory\Support\WarehouseLocationRole;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $warehouse_id
 * @property int $location_id
 * @property WarehouseLocationRole $role
 * @property-read Warehouse $warehouse
 * @property-read Location $location
 */
class WarehouseLocationAssignment extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $table = 'warehouse_location_roles';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (WarehouseLocationAssignment $assignment): void {
            if (! $assignment->isDirty(['warehouse_id', 'location_id'])) {
                return;
            }

            $location = Location::query()->find($assignment->location_id);
            if ($location === null || $location->warehouse_id !== $assignment->warehouse_id) {
                throw new DomainInvariantException(
                    'A warehouse role location must belong to that warehouse.',
                    'location_id',
                );
            }
        });
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['warehouse_id' => Warehouse::class];
    }

    protected function casts(): array
    {
        return ['role' => WarehouseLocationRole::class];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
