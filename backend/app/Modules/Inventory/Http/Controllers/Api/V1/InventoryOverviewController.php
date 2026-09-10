<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\BuildInventoryOverviewQuery;
use App\Modules\Inventory\Http\Requests\InventoryOverview\IndexInventoryOverviewRequest;
use App\Modules\Inventory\Http\Resources\InventoryOverviewResource;
use App\Modules\Inventory\Http\Resources\InventoryOverviewSummaryResource;
use App\Modules\Inventory\Models\Item;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only: on-hand/available/QA-held/on-order/shortage per stocked item —
 * see App\Modules\Inventory\Actions\BuildInventoryOverviewQuery for how
 * each figure is derived. Nothing is written here; this is a computed view
 * over stock_layers, avco_pools, goods_receipt_lines, purchase_order_lines
 * and negative_stock_shortages.
 */
class InventoryOverviewController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = ['category' => 'category'];

    /** @var array<string, string> */
    private const SORTABLE = ['code' => 'code', 'name' => 'name'];

    public function index(IndexInventoryOverviewRequest $request, BuildInventoryOverviewQuery $build): AnonymousResourceCollection
    {
        $query = $build();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        $this->applyFilters($query, $request);
        $this->applySort($query, $request, self::SORTABLE, 'name');

        return InventoryOverviewResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    /**
     * The value cards above the table — same filters as index(), no
     * pagination/sort/includes (irrelevant to a rolled-up total), aggregated
     * via a subquery wrapping the same per-item builder index() paginates.
     */
    public function summary(IndexInventoryOverviewRequest $request, BuildInventoryOverviewQuery $build): InventoryOverviewSummaryResource
    {
        $query = $build();
        $this->applyFilters($query, $request);

        $row = DB::query()->fromSub($query, 'inventory_overview_rows')->selectRaw(
            'COALESCE(SUM(on_hand_value), 0) as on_hand_value, '.
            'COALESCE(SUM(available_value), 0) as available_value, '.
            'COUNT(*) as item_count, '.
            'COUNT(*) FILTER (WHERE shortage_qty > 0) as shortage_item_count, '.
            'COUNT(*) FILTER (WHERE qa_held_qty > 0) as qa_held_item_count'
        )->first();

        return new InventoryOverviewSummaryResource($row);
    }

    /** @param  Builder<Item>  $query */
    private function applyFilters(Builder $query, IndexInventoryOverviewRequest $request): void
    {
        if ($request->filled('filter.category_id')) {
            $query->where('items.category_id', $request->integer('filter.category_id'));
        }
        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn ($q) => $q->where('items.code', 'ilike', "%{$search}%")
                ->orWhere('items.name', 'ilike', "%{$search}%"));
        }
    }
}
