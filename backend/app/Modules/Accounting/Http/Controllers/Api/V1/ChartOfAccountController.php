<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\ArchiveChartOfAccount;
use App\Modules\Accounting\Actions\CreateChartOfAccount;
use App\Modules\Accounting\Actions\UpdateChartOfAccount;
use App\Modules\Accounting\Http\Requests\ChartOfAccount\IndexChartOfAccountRequest;
use App\Modules\Accounting\Http\Requests\ChartOfAccount\ShowChartOfAccountRequest;
use App\Modules\Accounting\Http\Requests\ChartOfAccount\StoreChartOfAccountRequest;
use App\Modules\Accounting\Http\Requests\ChartOfAccount\UpdateChartOfAccountRequest;
use App\Modules\Accounting\Http\Resources\ChartOfAccountResource;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ChartOfAccountController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = [
        'parent' => 'parent',
        'children' => 'children',
    ];

    /** @var array<string, string> */
    private const SORTABLE = [
        'code' => 'code',
        'name' => 'name',
        'created_at' => 'created_at',
    ];

    public function index(IndexChartOfAccountRequest $request): AnonymousResourceCollection
    {
        $query = ChartOfAccount::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
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
        $this->applySort($query, $request, self::SORTABLE, 'code');

        return ChartOfAccountResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreChartOfAccountRequest $request, CreateChartOfAccount $create): JsonResponse
    {
        $chart_of_account = $create($request->validated(), $request->user());

        return (new ChartOfAccountResource($chart_of_account))->response()->setStatusCode(201);
    }

    public function show(ShowChartOfAccountRequest $request, ChartOfAccount $chart_of_account): ChartOfAccountResource
    {
        return new ChartOfAccountResource($chart_of_account->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function update(UpdateChartOfAccountRequest $request, ChartOfAccount $chart_of_account, UpdateChartOfAccount $update): ChartOfAccountResource
    {
        return new ChartOfAccountResource($update($chart_of_account, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, ChartOfAccount $chart_of_account, ArchiveChartOfAccount $archive): Response
    {
        $archive($chart_of_account, $request->user());

        return response()->noContent();
    }
}
