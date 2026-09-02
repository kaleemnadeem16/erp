<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $warehouse_id
 * @property string $code
 * @property string $name
 * @property bool $active
 * @property-read Warehouse $warehouse
 */
class Location extends Model
{
    use BelongsToCompanyThroughParent;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'bool'];
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
}
