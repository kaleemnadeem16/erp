<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit provenance for one target AVCO pool's opening state: which
 * policy transition (source method/scope/pool identity) contributed, which
 * exact target pool it landed in, and the resulting derived average. Not
 * itself live stock state — CommitInventoryCutoverOpeningState writes the
 * real avco_pools row separately; this row exists only because that write is
 * a derived aggregate with no other single source-evidence row proving it.
 *
 * @property int $inventory_cutover_id
 * @property int $item_id
 * @property int $policy_transition_id
 * @property int|null $warehouse_id
 * @property int|null $batch_id
 * @property int $target_avco_pool_id
 * @property string $opening_quantity
 * @property string $opening_value
 * @property string $base_opening_value
 * @property string $derived_average
 */
class InventoryCutoverAvcoPool extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Inventory cutover AVCO pool provenance is immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Inventory cutover AVCO pool provenance cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return [
            'inventory_cutover_id' => InventoryCutover::class, 'item_id' => Item::class,
            'policy_transition_id' => InventoryCutoverPolicyTransition::class,
            'warehouse_id' => Warehouse::class, 'batch_id' => StockBatch::class,
            'target_avco_pool_id' => AvcoPool::class,
        ];
    }

    protected function casts(): array
    {
        return [
            'opening_quantity' => 'decimal:4', 'opening_value' => 'decimal:4',
            'base_opening_value' => 'decimal:4', 'derived_average' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<InventoryCutover, $this> */
    public function cutover(): BelongsTo
    {
        return $this->belongsTo(InventoryCutover::class, 'inventory_cutover_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<InventoryCutoverPolicyTransition, $this> */
    public function policyTransition(): BelongsTo
    {
        return $this->belongsTo(InventoryCutoverPolicyTransition::class, 'policy_transition_id');
    }

    /** @return BelongsTo<AvcoPool, $this> */
    public function targetAvcoPool(): BelongsTo
    {
        return $this->belongsTo(AvcoPool::class, 'target_avco_pool_id');
    }
}
