<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Approvals\Actions\CreateApprovalAuthority;
use App\Modules\Approvals\Actions\RevokeApprovalAuthority;
use App\Modules\Approvals\Http\Requests\IndexApprovalAuthorityRequest;
use App\Modules\Approvals\Http\Requests\ManageApprovalAuthorityRequest;
use App\Modules\Approvals\Http\Requests\StoreApprovalAuthorityRequest;
use App\Modules\Approvals\Http\Resources\ApprovalAuthorityResource;
use App\Modules\Approvals\Models\ApprovalAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ApprovalAuthorityController extends Controller
{
    public function index(IndexApprovalAuthorityRequest $request): AnonymousResourceCollection
    {
        $query = ApprovalAuthority::query();
        foreach (['user_id', 'subject_type'] as $field) {
            if ($request->filled("filter.{$field}")) {
                $query->where($field, $request->input("filter.{$field}"));
            }
        }

        return ApprovalAuthorityResource::collection($query->orderBy('user_id')->orderByDesc('id')->get());
    }

    /** A person's own authority is theirs to see, whatever else they may do. */
    public function mine(Request $request): AnonymousResourceCollection
    {
        return ApprovalAuthorityResource::collection(ApprovalAuthority::query()->where('user_id', $request->user()->id)->orderByDesc('id')->get());
    }

    public function store(StoreApprovalAuthorityRequest $request, CreateApprovalAuthority $create): JsonResponse
    {
        return (new ApprovalAuthorityResource($create($request->validated(), $request->user()->id)))->response()->setStatusCode(201);
    }

    public function destroy(ManageApprovalAuthorityRequest $request, ApprovalAuthority $approval_authority, RevokeApprovalAuthority $revoke): ApprovalAuthorityResource
    {
        return new ApprovalAuthorityResource($revoke($approval_authority));
    }
}
