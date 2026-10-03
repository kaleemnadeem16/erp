<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Approvals\Actions\CreateApprovalDelegation;
use App\Modules\Approvals\Actions\RevokeApprovalDelegation;
use App\Modules\Approvals\Http\Requests\StoreApprovalDelegationRequest;
use App\Modules\Approvals\Http\Resources\ApprovalDelegationResource;
use App\Modules\Approvals\Models\ApprovalDelegation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Anyone may delegate their own authority — a delegate only ever acts within it. Delegating on someone else's behalf
 * (or seeing everyone's delegations) needs `approvals.delegation.manage`.
 */
class ApprovalDelegationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ApprovalDelegation::query()->with('delegator');
        if (! $request->user()->can('approvals.delegation.manage')) {
            $query->where(fn ($q) => $q->where('delegator_id', $request->user()->id)->orWhere('delegate_id', $request->user()->id));
        }

        return ApprovalDelegationResource::collection($query->orderByDesc('id')->get());
    }

    public function store(StoreApprovalDelegationRequest $request, CreateApprovalDelegation $create): JsonResponse
    {
        $data = $request->validated();
        $delegatorId = (int) ($data['delegator_id'] ?? $request->user()->id);
        abort_if($delegatorId !== $request->user()->id && ! $request->user()->can('approvals.delegation.manage'), 403, 'You can only delegate your own authority.');

        return (new ApprovalDelegationResource($create($data + ['delegator_id' => $delegatorId], $request->user())))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, ApprovalDelegation $approval_delegation, RevokeApprovalDelegation $revoke): ApprovalDelegationResource
    {
        abort_unless($approval_delegation->delegator_id === $request->user()->id || $request->user()->can('approvals.delegation.manage'), 403);

        return new ApprovalDelegationResource($revoke($approval_delegation, $request->user()));
    }
}
