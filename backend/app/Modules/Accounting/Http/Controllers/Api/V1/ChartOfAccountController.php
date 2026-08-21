<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\Requests\ChartOfAccount\IndexChartOfAccountRequest;
use App\Modules\Accounting\Http\Requests\ChartOfAccount\StoreChartOfAccountRequest;
use App\Modules\Accounting\Http\Requests\ChartOfAccount\UpdateChartOfAccountRequest;
use App\Modules\Accounting\Http\Resources\ChartOfAccountResource;
use App\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A hierarchy cycle or a parent_id from another company is rejected by
 * ChartOfAccount's own guard (GuardsCompanyScopedHierarchy) and surfaces as a
 * 422 via App\Exceptions\DomainInvariantException — see bootstrap/app.php and
 * App\Modules\Inventory\Http\Controllers\Api\V1\CategoryController, which
 * guards the identical shape.
 */
class ChartOfAccountController extends Controller
{
    /** @var array<string, string> */
    private const INCLUDABLE = [
        'parent' => 'parent',
        'children' => 'children',
    ];

    public function index(IndexChartOfAccountRequest $request): AnonymousResourceCollection
    {
        $query = ChartOfAccount::query();

        $this->applyIncludes($query, $request);

        if ($request->filled('filter.parent_id')) {
            $parentId = (int) $request->input('filter.parent_id');
            $parentId === 0 ? $query->whereNull('parent_id') : $query->where('parent_id', $parentId);
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

        $query->orderBy('code');

        $perPage = $request->integer('per_page', 25);

        return ChartOfAccountResource::collection($query->paginate($perPage));
    }

    public function store(StoreChartOfAccountRequest $request): JsonResponse
    {
        $account = ChartOfAccount::create($request->validated());

        // is_postable/status fall back to a Postgres column default when
        // omitted — see ItemController::store() for the same fix and why.
        $account->refresh();

        return (new ChartOfAccountResource($account))->response()->setStatusCode(201);
    }

    public function show(Request $request, ChartOfAccount $chart_of_account): ChartOfAccountResource
    {
        return new ChartOfAccountResource($chart_of_account->load($this->requestedIncludes($request)));
    }

    public function update(UpdateChartOfAccountRequest $request, ChartOfAccount $chart_of_account): ChartOfAccountResource
    {
        $chart_of_account->update($request->validated());

        return new ChartOfAccountResource($chart_of_account);
    }

    public function destroy(ChartOfAccount $chart_of_account): JsonResponse
    {
        $chart_of_account->delete();

        return response()->json(status: 204);
    }

    /**
     * @param  Builder<ChartOfAccount>  $query
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
