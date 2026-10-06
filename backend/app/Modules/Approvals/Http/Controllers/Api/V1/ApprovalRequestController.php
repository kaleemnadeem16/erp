<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Approvals\Actions\DecideApproval;
use App\Modules\Approvals\Actions\WithdrawApproval;
use App\Modules\Approvals\Http\Requests\DecideApprovalRequest;
use App\Modules\Approvals\Http\Requests\IndexApprovalRequestRequest;
use App\Modules\Approvals\Http\Requests\WithdrawApprovalRequest;
use App\Modules\Approvals\Http\Resources\ApprovalRequestResource;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Modules\Approvals\Support\ApprovalInbox;
use App\Modules\Approvals\Support\ApprovalRequestAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ApprovalRequestController extends Controller
{
    /** Everything, for people who may audit approvals. */
    public function index(IndexApprovalRequestRequest $request): AnonymousResourceCollection
    {
        $query = ApprovalRequest::query();
        foreach (['subject_type', 'subject_id', 'status'] as $field) {
            if ($request->filled("filter.{$field}")) {
                $query->where($field, $request->input("filter.{$field}"));
            }
        }

        return ApprovalRequestResource::collection($query->orderByDesc('id')->paginate($request->integer('per_page', 25)));
    }

    /** What this person can decide right now. Needs no permission of its own: an empty inbox is the honest answer. */
    public function inbox(Request $request, ApprovalInbox $inbox): AnonymousResourceCollection
    {
        return ApprovalRequestResource::collection($inbox->forUser($request->user()));
    }

    /** A request is visible to who submitted it, who may audit approvals, and anyone who can act on or has acted on it. */
    public function show(Request $request, ApprovalRequest $approval_request, ApprovalInbox $inbox): ApprovalRequestResource
    {
        $user = $request->user();
        $approval_request->load(['steps.policyStep', 'steps.decisions']);
        $involved = app(ApprovalRequestAccess::class)->canView($user, $approval_request);
        abort_unless($involved, 403, 'This approval is not yours to see.');

        return new ApprovalRequestResource($approval_request);
    }

    public function decide(DecideApprovalRequest $request, ApprovalRequest $approval_request, DecideApproval $decide): ApprovalRequestResource
    {
        $data = $request->validated();

        return new ApprovalRequestResource($decide($approval_request, $request->user(), ApprovalDecisionKind::from($data['decision']), $data['reason'] ?? null, $data['step_id'] ?? null));
    }

    public function withdraw(WithdrawApprovalRequest $request, ApprovalRequest $approval_request, WithdrawApproval $withdraw): ApprovalRequestResource
    {
        return new ApprovalRequestResource($withdraw($approval_request, $request->user(), $request->validated('reason')));
    }
}
