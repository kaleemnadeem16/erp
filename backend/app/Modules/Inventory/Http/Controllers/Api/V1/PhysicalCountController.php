<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\AddPhysicalCountLine;
use App\Modules\Inventory\Actions\CancelPhysicalCount;
use App\Modules\Inventory\Actions\CreatePhysicalCount;
use App\Modules\Inventory\Actions\PostPhysicalCount;
use App\Modules\Inventory\Actions\RecordPhysicalCountLine;
use App\Modules\Inventory\Http\Requests\PhysicalCount\IndexPhysicalCountRequest;
use App\Modules\Inventory\Http\Requests\PhysicalCount\PostPhysicalCountRequest;
use App\Modules\Inventory\Http\Requests\PhysicalCount\RecordPhysicalCountLineRequest;
use App\Modules\Inventory\Http\Requests\PhysicalCount\ShowPhysicalCountRequest;
use App\Modules\Inventory\Http\Requests\PhysicalCount\StorePhysicalCountLineRequest;
use App\Modules\Inventory\Http\Requests\PhysicalCount\StorePhysicalCountRequest;
use App\Modules\Inventory\Http\Resources\PhysicalCountLineResource;
use App\Modules\Inventory\Http\Resources\PhysicalCountResource;
use App\Modules\Inventory\Models\PhysicalCount;
use App\Modules\Inventory\Models\PhysicalCountLine;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    ];

    /** @var array<string, string> */
    private const SORTABLE = ['created_at' => 'created_at', 'posted_at' => 'posted_at'];

    public function index(IndexPhysicalCountRequest $request): AnonymousResourceCollection
    {
        $query = PhysicalCount::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);

        if ($request->filled('filter.warehouse_id')) {
            $query->where('warehouse_id', $request->integer('filter.warehouse_id'));
        }
        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        $this->applySort($query, $request, self::SORTABLE, '-created_at');

        return PhysicalCountResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StorePhysicalCountRequest $request, CreatePhysicalCount $create): JsonResponse
    {
        $count = $create($request->validated(), $request->user());

        return (new PhysicalCountResource($count))->response()->setStatusCode(201);
    }

    public function show(ShowPhysicalCountRequest $request, PhysicalCount $physicalCount): PhysicalCountResource
    {
        return new PhysicalCountResource($physicalCount->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function storeLine(StorePhysicalCountLineRequest $request, PhysicalCount $physicalCount, AddPhysicalCountLine $add): JsonResponse
    {
        $line = $add($physicalCount, $request->validated(), $request->user());

        return (new PhysicalCountLineResource($line))->response()->setStatusCode(201);
    }

    public function count(RecordPhysicalCountLineRequest $request, PhysicalCountLine $physicalCountLine, RecordPhysicalCountLine $record): PhysicalCountLineResource
    {
        $line = $record($physicalCountLine, $request->string('counted_qty')->toString(), $request->user());

        return new PhysicalCountLineResource($line);
    }

    public function post(PostPhysicalCountRequest $request, PhysicalCount $physicalCount, PostPhysicalCount $post): PhysicalCountResource
    {
        /** @var array<int, array{physical_count_line_id: int, unit_cost: string}> $pairs */
        $pairs = $request->validated('found_costs', []);
        $foundCosts = array_column($pairs, 'unit_cost', 'physical_count_line_id');

        return new PhysicalCountResource($post($physicalCount, $foundCosts, $request->user()));
    }

    public function cancel(Request $request, PhysicalCount $physicalCount, CancelPhysicalCount $cancel): PhysicalCountResource
    {
        return new PhysicalCountResource($cancel($physicalCount, $request->user()));
    }
}
