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
        $item = $create($request->validated());

        return (new ItemResource($item))->response()->setStatusCode(201);
    }

    public function show(ShowItemRequest $request, Item $item): ItemResource
    {
        return new ItemResource($item->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function update(UpdateItemRequest $request, Item $item, UpdateItem $update): ItemResource
    {
        return new ItemResource($update($item, $request->validated()));
    }

    public function destroy(Request $request, Item $item, ArchiveItem $archive): Response
    {
        abort_unless($request->user()?->can('inventory.item.manage'), 403);
        $archive($item);

        return response()->noContent();
    }
}
