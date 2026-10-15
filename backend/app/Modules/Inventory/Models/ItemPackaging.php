<?php

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Modules\Inventory\Support\ItemQuantityRules;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;

/**
 * @property int $id
 * @property int $item_id
 * @property string $code
 * @property string $name
 * @property string $stock_quantity
 * @property string|null $quantity_precision
 * @property string|null $barcode
 * @property int|null $inner_packaging_id
 * @property int|null $inner_count
 * @property bool $active
 */
class ItemPackaging extends Model
{
    use BelongsToCompany,GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['stock_quantity' => 'decimal:9', 'quantity_precision' => 'decimal:9', 'active' => 'bool'];
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['item_id' => Item::class, 'inner_packaging_id' => self::class];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $record) => ItemQuantityRules::assertPackaging($record));
        static::updating(function (self $record): void {
            if ($record->isDirty(['item_id', 'code', 'stock_quantity', 'quantity_precision', 'inner_packaging_id', 'inner_count'])) {
                throw new DomainInvariantException('Identity/content cannot be repointed; archive and create a replacement.');
            }
        });
        static::deleting(fn () => throw new DomainInvariantException('Archive instead of deleting.'));
    }
}
