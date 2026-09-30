<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Approvals\Actions\ActivateApprovalPolicy;
use App\Modules\Approvals\Actions\CreateApprovalPolicy;
use App\Modules\Approvals\Actions\DeleteApprovalPolicy;
use App\Modules\Approvals\Actions\UpdateApprovalPolicy;
use App\Modules\Approvals\Http\Requests\IndexApprovalPolicyRequest;
use App\Modules\Approvals\Http\Requests\ManageApprovalPolicyRequest;
use App\Modules\Approvals\Http\Requests\StoreApprovalPolicyRequest;
use App\Modules\Approvals\Http\Requests\UpdateApprovalPolicyRequest;
use App\Modules\Approvals\Http\Resources\ApprovalPolicyResource;
use App\Modules\Approvals\Models\ApprovalPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ApprovalPolicyController extends Controller
{
    public function index(IndexApprovalPolicyRequest $request): AnonymousResourceCollection
    {
        $query = ApprovalPolicy::query()->with(['conditions', 'steps']);
        foreach (['subject_type', 'status', 'code'] as $field) {
            if ($request->filled("filter.{$field}")) {
                $query->where($field, $request->input("filter.{$field}"));
            }
        }

        return ApprovalPolicyResource::collection($query->orderBy('subject_type')->orderBy('code')->orderByDesc('version_no')->paginate($request->integer('per_page', 50)));
    }

    public function show(IndexApprovalPolicyRequest $request, ApprovalPolicy $approval_policy): ApprovalPolicyResource
    {
        return new ApprovalPolicyResource($approval_policy->load(['conditions', 'steps']));
    }

    public function store(StoreApprovalPolicyRequest $request, CreateApprovalPolicy $create): JsonResponse
    {
        return (new ApprovalPolicyResource($create($request->validated(), $request->user()->id)))->response()->setStatusCode(201);
    }

    public function update(UpdateApprovalPolicyRequest $request, ApprovalPolicy $approval_policy, UpdateApprovalPolicy $update): ApprovalPolicyResource
    {
        return new ApprovalPolicyResource($update($approval_policy, $request->validated(), $request->user()->id));
    }

    public function destroy(ManageApprovalPolicyRequest $request, ApprovalPolicy $approval_policy, DeleteApprovalPolicy $delete): Response
    {
        $delete($approval_policy);

        return response()->noContent();
    }

    public function activate(ManageApprovalPolicyRequest $request, ApprovalPolicy $approval_policy, ActivateApprovalPolicy $activate): ApprovalPolicyResource
    {
        return new ApprovalPolicyResource($activate($approval_policy));
    }
}
