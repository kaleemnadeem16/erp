<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\Item\IndexItemRequest;
use App\Modules\Inventory\Http\Requests\Item\StoreItemRequest;
use App\Modules\Inventory\Http\Requests\Item\UpdateItemRequest;
use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * destroy() soft-deletes (Item uses SoftDeletes) — see docs/domain/items.md
 * "Archiving, not deleting". A missing valuation_method with no category default
 * anywhere in the ancestry is rejected by Item::booted() and surfaces as a 422
 * via App\Exceptions\DomainInvariantException, not an uncaught 500 — see
 * bootstrap/app.php.
 */
class ItemController extends Controller
{
    /** @var array<string, string> */
    private const INCLUDABLE = [
        'category' => 'category',
    ];

    public function index(IndexItemRequest $request): AnonymousResourceCollection
    {
        $query = Item::query();

        $this->applyIncludes($query, $request);

        if ($request->filled('filter.category_id')) {
            $query->where('category_id', $request->input('filter.category_id'));
        }

        if ($request->filled('filter.type')) {
            $query->where('type', $request->input('filter.type'));
        }

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', "%{$search}%")
                ->orWhere('code', 'ilike', "%{$search}%"));
        }

        $query->orderBy('name');

        $perPage = $request->integer('per_page', 25);

        return ItemResource::collection($query->paginate($perPage));
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = Item::create($request->validated());

        // is_stocked/is_purchasable/is_sellable/tracking/status all fall back to a
        // Postgres column default when omitted — the in-memory model from create()
        // never sees that value, only the DB row does, so serialising it directly
        // would return null for a field the response schema declares non-nullable.
        $item->refresh();

        return (new ItemResource($item))->response()->setStatusCode(201);
    }

    public function show(Request $request, Item $item): ItemResource
    {
        return new ItemResource($item->load($this->requestedIncludes($request)));
    }

    public function update(UpdateItemRequest $request, Item $item): ItemResource
    {
        $item->update($request->validated());

        return new ItemResource($item);
    }

    public function destroy(Item $item): JsonResponse
    {
        $item->delete();

        return response()->json(status: 204);
    }

    /**
     * @param  Builder<Item>  $query
     */
    private function applyIncludes(Builder $query, Request $request): void
    {
        foreach ($this->requestedIncludes($request) as $relation) {
            $query->with($relation);
        }
    }

    /**
     * @return list<string>
     */
    private function requestedIncludes(Request $request): array
    {
        $requested = array_filter(explode(',', (string) $request->query('include', '')));

        return array_values(array_intersect_key(self::INCLUDABLE, array_flip($requested)));
    }
}
