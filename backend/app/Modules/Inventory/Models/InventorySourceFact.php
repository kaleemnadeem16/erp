<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
use App\Models\Model;
use App\Models\Uom;
use App\Modules\Imports\Models\ImportBatchRow;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable normalised source evidence. It is not live stock state.
 *
 * @property ValuationMethod $source_valuation_method
 * @property AvcoPoolScope|null $source_avco_scope
 * @property string $qty
 * @property string|null $unit_cost
 * @property string $amount
 * @property string $base_amount
 * @property string|null $fx_rate
 * @property array<string, mixed> $source_payload
 */
class InventorySourceFact extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Inventory source facts are immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Inventory source facts cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return [
            'inventory_cutover_id' => InventoryCutover::class,
            'inventory_cutover_batch_id' => InventoryCutoverBatch::class,
            'item_id' => Item::class, 'location_id' => Location::class, 'uom_id' => Uom::class,
        ];
    }

    protected function casts(): array
    {
        return [
            'source_valuation_method' => ValuationMethod::class, 'source_avco_scope' => AvcoPoolScope::class,
            'source_effective_at' => 'immutable_datetime', 'source_posting_date' => 'immutable_date',
            'qty' => 'decimal:4', 'unit_cost' => 'decimal:4', 'amount' => 'decimal:4', 'base_amount' => 'decimal:4',
            'fx_rate' => 'decimal:9', 'source_payload' => 'array',
        ];
    }

    /** @return BelongsTo<InventoryCutover, $this> */
    public function cutover(): BelongsTo
    {
        return $this->belongsTo(InventoryCutover::class, 'inventory_cutover_id');
    }

    /** @return BelongsTo<InventoryCutoverBatch, $this> */
    public function cutoverBatch(): BelongsTo
    {
        return $this->belongsTo(InventoryCutoverBatch::class, 'inventory_cutover_batch_id');
    }

    /** @return BelongsTo<ImportBatchRow, $this> */
    public function importRow(): BelongsTo
    {
        return $this->belongsTo(ImportBatchRow::class, 'import_batch_row_id');
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
