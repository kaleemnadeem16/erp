<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property ValuationMethod $source_valuation_method
 * @property AvcoPoolScope|null $source_avco_scope
 * @property string|null $source_pool_identity
 * @property ValuationMethod $target_valuation_method
 * @property AvcoPoolScope|null $target_avco_scope
 * @property Carbon $effective_at
 * @property array<string, mixed>|null $revaluation_basis
 */
class InventoryCutoverPolicyTransition extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Inventory cutover policy transitions are immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Inventory cutover policy transitions cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['inventory_cutover_id' => InventoryCutover::class, 'item_id' => Item::class];
    }

    protected function casts(): array
    {
        return [
            'source_valuation_method' => ValuationMethod::class,
            'source_avco_scope' => AvcoPoolScope::class,
            'target_valuation_method' => ValuationMethod::class,
            'target_avco_scope' => AvcoPoolScope::class,
            'effective_at' => 'immutable_datetime',
            'revaluation_basis' => 'array',
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
}
