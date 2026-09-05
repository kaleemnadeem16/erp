<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Recalculation;

use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ValuationMethod;
use Illuminate\Support\Collection;

/** Read-only input to the internal movement replayer; no model here is saved. */
final readonly class MovementReplayHistory
{
    /**
     * @param  Collection<int, StockMove>  $moves
     * @param  Collection<int, StockLayer>  $layers
     * @param  Collection<int, Location>  $locations  keyed by id
     * @param  Collection<int, AvcoPool>  $pools
     * @param  list<string>  $keys
     */
    public function __construct(
        public Item $item,
        public Collection $moves,
        public Collection $layers,
        public Collection $locations,
        public Collection $pools,
        public array $keys,
    ) {}

    public function key(int $locationId, ?int $batchId): string
    {
        if ($this->item->valuation_method !== ValuationMethod::Avco) {
            return 'location:'.$locationId;
        }

        return match ($this->item->avco_pool_scope) {
            AvcoPoolScope::WarehouseItem => 'warehouse:'.$this->locations->get($locationId)->warehouse_id,
            AvcoPoolScope::CompanyItemBatch => 'batch:'.$batchId,
            default => 'company',
        };
    }

    public function poolKey(AvcoPool $pool): string
    {
        return match ($this->item->avco_pool_scope) {
            AvcoPoolScope::WarehouseItem => 'warehouse:'.$pool->warehouse_id,
            AvcoPoolScope::CompanyItemBatch => 'batch:'.$pool->batch_id,
            default => 'company',
        };
    }
}
