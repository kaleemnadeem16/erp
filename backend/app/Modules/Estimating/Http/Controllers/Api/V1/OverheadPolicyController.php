<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Controllers\Api\V1;

use App\Exceptions\DomainInvariantException;
use App\Http\Controllers\Controller;
use App\Modules\Estimating\Actions\ActivateOverheadPolicy;
use App\Modules\Estimating\Actions\CreateOverheadPolicy;
use App\Modules\Estimating\Actions\DeleteOverheadPolicy;
use App\Modules\Estimating\Actions\ResolveOverheadPolicy;
use App\Modules\Estimating\Actions\UpdateOverheadPolicy;
use App\Modules\Estimating\Http\Requests\OverheadPolicy\IndexOverheadPolicyRequest;
use App\Modules\Estimating\Http\Requests\OverheadPolicy\ResolveOverheadPolicyRequest;
use App\Modules\Estimating\Http\Requests\OverheadPolicy\ShowOverheadPolicyRequest;
use App\Modules\Estimating\Http\Requests\OverheadPolicy\StoreOverheadPolicyRequest;
use App\Modules\Estimating\Http\Requests\OverheadPolicy\UpdateOverheadPolicyRequest;
use App\Modules\Estimating\Http\Resources\OverheadPolicyResource;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\OverheadPolicy;
use App\Modules\Estimating\Support\OverheadAmountCalculator;
use App\Modules\Estimating\Support\OverheadCostBasis;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class OverheadPolicyController extends Controller
{
    use AppliesApiQuery;

    private const INCLUDABLE = ['changedBy' => 'changedBy'];

    private const SORTABLE = ['effective_from' => 'effective_from', 'created_at' => 'created_at'];

    public function index(IndexOverheadPolicyRequest $request): AnonymousResourceCollection
    {
        $query = OverheadPolicy::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        foreach (['scope_type', 'scope_id', 'status'] as $field) {
            if ($request->filled("filter.{$field}")) {
                $query->where($field, $request->input("filter.{$field}"));
            }
        }
        $this->applySort($query, $request, self::SORTABLE, '-created_at');

        return OverheadPolicyResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(ShowOverheadPolicyRequest $request, OverheadPolicy $overhead_policy): OverheadPolicyResource
    {
        return new OverheadPolicyResource($overhead_policy->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function store(StoreOverheadPolicyRequest $request, CreateOverheadPolicy $create): JsonResponse
    {
        return (new OverheadPolicyResource($create($request->validated(), $request->user())))
            ->response()->setStatusCode(201);
    }

    public function update(UpdateOverheadPolicyRequest $request, OverheadPolicy $overhead_policy, UpdateOverheadPolicy $update): OverheadPolicyResource
    {
        return new OverheadPolicyResource($update($overhead_policy, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, OverheadPolicy $overhead_policy, DeleteOverheadPolicy $delete): Response
    {
        if (! $request->user()?->can('estimating.overhead-policy.manage')) {
            throw new DomainInvariantException('The actor is not permitted to delete an overhead policy.', 'status');
        }

        $delete($overhead_policy, $request->user());

        return response()->noContent();
    }

    public function activate(Request $request, OverheadPolicy $overhead_policy, ActivateOverheadPolicy $activate): OverheadPolicyResource
    {
        if (! $request->user()?->can('estimating.overhead-policy.manage')) {
            throw new DomainInvariantException('The actor is not permitted to activate an overhead policy.', 'status');
        }

        return new OverheadPolicyResource($activate($overhead_policy, $request->user()));
    }

    /**
     * Read-only preview of ADR 0049's resolution — which policy wins for an
     * assembly. Monetary policy values and the computed amount require
     * policy-management, policy-history or internal-cost access; otherwise
     * arbitrary basis inputs could reveal the configured rate.
     */
    public function resolve(ResolveOverheadPolicyRequest $request, ResolveOverheadPolicy $resolve, OverheadAmountCalculator $calculate): JsonResponse
    {
        $assembly = Assembly::query()->findOrFail($request->integer('assembly_id'));
        $resolution = $resolve($assembly);
        $maySeeRate = $request->user()?->can('estimating.overhead-policy.manage')
            || $request->user()?->can('estimating.policy-history.view')
            || $request->user()?->can('estimating.internal_cost.view');

        $basis = new OverheadCostBasis(
            materialCost: $request->input('material_cost', '0'),
            labourCost: $request->input('labour_cost', '0'),
            plantCost: $request->input('plant_cost', '0'),
            subcontractCost: $request->input('subcontract_cost', '0'),
            labourHours: $request->input('labour_hours', '0'),
            workQty: $request->input('work_qty', '0'),
        );

        return response()->json([
            'data' => [
                'method' => $resolution->method->value,
                'policy' => $resolution->policy === null ? null : new OverheadPolicyResource($resolution->policy),
                'overhead_amount' => $maySeeRate
                    ? ($resolution->policy === null
                        ? '0.0000'
                        : (string) $calculate($resolution->policy->driver_type, $resolution->policy->rate, $basis))
                    : null,
            ],
        ]);
    }
}
