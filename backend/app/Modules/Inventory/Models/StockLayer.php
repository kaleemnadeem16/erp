<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Support\Carbon;

/**
 * @property int $item_id
 * @property int $location_id
 * @property int|null $batch_id
 * @property string|null $serial_no
 * @property string $qty_received
 * @property string $qty_remaining
 * @property string $unit_cost
 * @property int $currency_id
 * @property string $fx_rate
 * @property string $base_unit_cost
 * @property Carbon $received_at
 * @property string $source_type
 * @property int $source_id
 * @property int|null $source_line_no
 */
class StockLayer extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (StockLayer $layer): void {
            if (array_diff(array_keys($layer->getDirty()), ['qty_remaining', 'updated_at']) !== []) {
                throw new DomainInvariantException('Receipt-layer facts are immutable; only remaining quantity may be consumed.');
            }
        });
        static::deleting(fn () => throw new DomainInvariantException('Stock layers cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['item_id' => Item::class, 'location_id' => Location::class, 'batch_id' => StockBatch::class];
    }

    protected function casts(): array
    {
        return [
            'qty_received' => 'decimal:4', 'qty_remaining' => 'decimal:4',
            'unit_cost' => 'decimal:4', 'fx_rate' => 'decimal:9',
            'base_unit_cost' => 'decimal:4', 'received_at' => 'immutable_datetime',
            'source_line_no' => 'integer',
        ];
    }
}
