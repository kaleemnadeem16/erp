<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\AddPhysicalCountLine;
use App\Modules\Inventory\Actions\CancelPhysicalCount;
use App\Modules\Inventory\Actions\CreatePhysicalCount;
use App\Modules\Inventory\Actions\PostPhysicalCount;
use App\Modules\Inventory\Actions\RecordPhysicalCountComposition;
use App\Modules\Inventory\Actions\RecordPhysicalCountLine;
use App\Modules\Inventory\Actions\RefreshPhysicalCountLine;
use App\Modules\Inventory\Http\Requests\PhysicalCount\CancelPhysicalCountRequest;
use App\Modules\Inventory\Http\Requests\PhysicalCount\IndexPhysicalCountRequest;
use App\Modules\Inventory\Http\Requests\PhysicalCount\PostPhysicalCountRequest;
use App\Modules\Inventory\Http\Requests\PhysicalCount\RecordPhysicalCountCompositionRequest;
use App\Modules\Inventory\Http\Requests\PhysicalCount\RecordPhysicalCountLineRequest;
use App\Modules\Inventory\Http\Requests\PhysicalCount\RefreshPhysicalCountLineRequest;
use App\Modules\Inventory\Http\Requests\PhysicalCount\ShowPhysicalCountRequest;
use App\Modules\Inventory\Http\Requests\PhysicalCount\StorePhysicalCountLineRequest;
use App\Modules\Inventory\Http\Requests\PhysicalCount\StorePhysicalCountRequest;
use App\Modules\Inventory\Http\Resources\PhysicalCountLineResource;
use App\Modules\Inventory\Http\Resources\PhysicalCountResource;
use App\Modules\Inventory\Models\PhysicalCount;
use App\Modules\Inventory\Models\PhysicalCountLine;
use App\Modules\Inventory\Support\PhysicalCountReadAccess;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The header (PhysicalCount) is create/read-only over HTTP — every state
 * change is a named action (add a line, count a line, post, cancel), each
 * delegating straight to its Action; see docs/domain/physical-count.md.
 */
class PhysicalCountController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = [
        'warehouse' => 'warehouse',
        'lines' => 'lines',
        'lines.item' => 'lines.item',
        'lines.location' => 'lines.location',
        'lines.entries' => 'lines.entries',
        'lines.composition' => 'lines.composition',
    ];

    /** @var array<string, string> */
    private const SORTABLE = ['created_at' => 'created_at', 'posted_at' => 'posted_at'];

    public function index(IndexPhysicalCountRequest $request): AnonymousResourceCollection
    {
        $query = PhysicalCount::query();
        // Only the warehouses the person's units own (ADR 0089).
        WarehouseScope::limitToWarehouse($query, $request->user(), PhysicalCountReadAccess::PERMISSIONS);
        $this->applyIncludes($query, $request, self::INCLUDABLE);

        if ($request->filled('filter.warehouse_id')) {
            $query->where('warehouse_id', $request->integer('filter.warehouse_id'));
        }
        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }
        if ($request->filled('filter.search')) {
            $query->where('reference', 'ilike', '%'.$request->string('filter.search')->toString().'%');
        }

        $this->applySort($query, $request, self::SORTABLE, '-created_at');

        return PhysicalCountResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StorePhysicalCountRequest $request, CreatePhysicalCount $create): JsonResponse
    {
        abort_unless(WarehouseScope::permitsWarehouse($request->user(), 'inventory.physical_count.create', $request->integer('warehouse_id')), 403, 'You cannot count in that warehouse.');
        $count = $create($request->validated(), $request->user());

        return (new PhysicalCountResource($count))->response()->setStatusCode(201);
    }

    public function show(ShowPhysicalCountRequest $request, PhysicalCount $physicalCount): PhysicalCountResource
    {
        abort_unless(WarehouseScope::permitsWarehouse($request->user(), PhysicalCountReadAccess::PERMISSIONS, $physicalCount->warehouse_id), 404);

        return new PhysicalCountResource($physicalCount->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function storeLine(StorePhysicalCountLineRequest $request, PhysicalCount $physicalCount, AddPhysicalCountLine $add): JsonResponse
    {
        abort_unless(WarehouseScope::permitsWarehouse($request->user(), 'inventory.physical_count.create', $physicalCount->warehouse_id), 404);
        $line = $add($physicalCount, $request->validated(), $request->user());

        return (new PhysicalCountLineResource($line))->response()->setStatusCode(201);
    }

    public function count(RecordPhysicalCountLineRequest $request, PhysicalCountLine $physicalCountLine, RecordPhysicalCountLine $record): PhysicalCountLineResource
    {
        abort_unless(WarehouseScope::permitsWarehouse($request->user(), 'inventory.physical_count.count', (int) PhysicalCount::query()->whereKey($physicalCountLine->physical_count_id)->value('warehouse_id')), 404);
        $line = $record($physicalCountLine, $request->string('counted_qty')->toString(), $request->user());

        return new PhysicalCountLineResource($line);
    }

    public function composition(RecordPhysicalCountCompositionRequest $request, PhysicalCountLine $physicalCountLine, RecordPhysicalCountComposition $record): PhysicalCountLineResource
    {
        abort_unless(WarehouseScope::permitsWarehouse($request->user(), 'inventory.physical_count.count', (int) PhysicalCount::query()->whereKey($physicalCountLine->physical_count_id)->value('warehouse_id')), 404);
        /** @var list<array{packaging_id?: int|null, count?: int, quantity?: string}> $rows */
        $rows = array_map(fn (array $row): array => array_filter($row, fn ($value): bool => $value !== null), $request->validated('composition'));

        return new PhysicalCountLineResource($record($physicalCountLine, $rows, $request->user())->load('composition'));
    }

    public function refresh(RefreshPhysicalCountLineRequest $request, PhysicalCountLine $physicalCountLine, RefreshPhysicalCountLine $refresh): PhysicalCountLineResource
    {
        abort_unless(WarehouseScope::permitsWarehouse($request->user(), 'inventory.physical_count.create', (int) PhysicalCount::query()->whereKey($physicalCountLine->physical_count_id)->value('warehouse_id')), 404);

        return new PhysicalCountLineResource($refresh($physicalCountLine, $request->user())->load('composition'));
    }

    public function post(PostPhysicalCountRequest $request, PhysicalCount $physicalCount, PostPhysicalCount $post): PhysicalCountResource
    {
        abort_unless(WarehouseScope::permitsWarehouse($request->user(), 'inventory.physical_count.post', $physicalCount->warehouse_id), 404);
        /** @var array<int, array{physical_count_line_id: int, unit_cost: string}> $pairs */
        $pairs = $request->validated('found_costs', []);
        $foundCosts = array_column($pairs, 'unit_cost', 'physical_count_line_id');

        return new PhysicalCountResource($post($physicalCount, $foundCosts, $request->user()));
    }

    public function cancel(CancelPhysicalCountRequest $request, PhysicalCount $physicalCount, CancelPhysicalCount $cancel): PhysicalCountResource
    {
        abort_unless(WarehouseScope::permitsWarehouse($request->user(), 'inventory.physical_count.create', $physicalCount->warehouse_id), 404);

        return new PhysicalCountResource($cancel($physicalCount, $request->user()));
    }
}
