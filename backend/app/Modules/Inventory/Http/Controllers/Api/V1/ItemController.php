<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\ArchiveItem;
use App\Modules\Inventory\Actions\CreateItem;
use App\Modules\Inventory\Actions\UpdateItem;
use App\Modules\Inventory\Http\Requests\Item\IndexItemRequest;
use App\Modules\Inventory\Http\Requests\Item\ShowItemRequest;
use App\Modules\Inventory\Http\Requests\Item\StoreItemRequest;
use App\Modules\Inventory\Http\Requests\Item\UpdateItemRequest;
use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Inventory\Models\Item;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * destroy() soft-deletes (Item uses SoftDeletes) — see docs/domain/items.md
 * "Archiving, not deleting". A missing valuation_method with no category default
 * anywhere in the ancestry is rejected by Item::booted() and surfaces as a 422
 * via App\Exceptions\DomainInvariantException, not an uncaught 500 — see
 * bootstrap/app.php.
 */
class ItemController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = [
        'category' => 'category',
    ];

    /** @var array<string, string> */
    private const SORTABLE = [
        'code' => 'code',
        'name' => 'name',
        'created_at' => 'created_at',
    ];

    public function index(IndexItemRequest $request): AnonymousResourceCollection
    {
        $query = Item::query();

        $this->applyIncludes($query, $request, self::INCLUDABLE);

        if ($request->filled('filter.category_id')) {
            $query->where('category_id', $request->input('filter.category_id'));
        }

        if ($request->filled('filter.type')) {
            $query->where('type', $request->input('filter.type'));
        }

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        if ($request->has('filter.is_stocked') && $request->input('filter.is_stocked') !== null && $request->input('filter.is_stocked') !== '') {
            $query->where('is_stocked', $request->boolean('filter.is_stocked'));
        }

        if ($request->has('filter.is_purchasable') && $request->input('filter.is_purchasable') !== null && $request->input('filter.is_purchasable') !== '') {
            $query->where('is_purchasable', $request->boolean('filter.is_purchasable'));
        }

        if ($request->has('filter.is_sellable') && $request->input('filter.is_sellable') !== null && $request->input('filter.is_sellable') !== '') {
            $query->where('is_sellable', $request->boolean('filter.is_sellable'));
        }

        if ($request->filled('filter.valuation_method')) {
            $query->where('valuation_method', $request->input('filter.valuation_method'));
        }

        if ($request->filled('filter.tracking')) {
            $query->where('tracking', $request->input('filter.tracking'));
        }

        if ($request->filled('filter.supplier_id')) {
            $supplierId = $request->input('filter.supplier_id');
            $query->whereHas('suppliers', fn (Builder $q) => $q->where('supplier_account_id', $supplierId)->where('active', true));
        }

        if ($request->has('filter.has_supplier') && $request->input('filter.has_supplier') !== null && $request->input('filter.has_supplier') !== '') {
            if ($request->boolean('filter.has_supplier')) {
                $query->whereHas('suppliers', fn (Builder $q) => $q->where('active', true));
            } else {
                $query->whereDoesntHave('suppliers', fn (Builder $q) => $q->where('active', true));
            }
        }

        if ($request->has('filter.has_packaging') && $request->input('filter.has_packaging') !== null && $request->input('filter.has_packaging') !== '') {
            if ($request->boolean('filter.has_packaging')) {
                $query->whereHas('packagings', fn (Builder $q) => $q->where('active', true));
            } else {
                $query->whereDoesntHave('packagings', fn (Builder $q) => $q->where('active', true));
            }
        }

        if ($request->filled('filter.stock_uom_id')) {
            $query->where('stock_uom_id', $request->input('filter.stock_uom_id'));
        }

        if ($request->filled('filter.package_tracking_mode')) {
            $query->where('package_tracking_mode', $request->input('filter.package_tracking_mode'));
        }

        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', "%{$search}%")
                ->orWhere('code', 'ilike', "%{$search}%"));
        }

        $this->applySort($query, $request, self::SORTABLE, 'name');

        $perPage = $request->integer('per_page', 25);

        return ItemResource::collection($query->paginate($perPage));
    }

    public function store(StoreItemRequest $request, CreateItem $create): JsonResponse
    {
        $item = $create($request->validated(), $request->user());

        return (new ItemResource($item))->response()->setStatusCode(201);
    }

    public function show(ShowItemRequest $request, Item $item): ItemResource
    {
        return new ItemResource($item->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function update(UpdateItemRequest $request, Item $item, UpdateItem $update): ItemResource
    {
        return new ItemResource($update($item, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, Item $item, ArchiveItem $archive): Response
    {
        abort_unless($request->user()?->can('inventory.item.manage'), 403);
        $archive($item, $request->user());

        return response()->noContent();
    }
}
