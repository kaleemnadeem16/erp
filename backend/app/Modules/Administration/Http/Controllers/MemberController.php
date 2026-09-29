<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Administration\Actions\ChangeMemberStatus;
use App\Modules\Administration\Actions\InviteMember;
use App\Modules\Administration\Actions\IssueTemporaryMemberPassword;
use App\Modules\Administration\Actions\SyncMemberAccess;
use App\Modules\Administration\Actions\UpdateMember;
use App\Modules\Administration\Http\Requests\StoreMemberRequest;
use App\Modules\Administration\Http\Requests\UpdateMemberAccessRequest;
use App\Modules\Administration\Http\Requests\UpdateMemberRequest;
use App\Modules\Administration\Http\Resources\MemberResource;
use App\Modules\Administration\Notifications\MemberInvitation;
use App\Modules\Administration\Support\MemberStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Password;
use Spatie\Activitylog\Models\Activity;

class MemberController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('administration.member.view'), 403);
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'in:INVITED,ACTIVE,SUSPENDED'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim($filters['search'] ?? '');

        return MemberResource::collection(
            User::query()
                ->with('roles')
                ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
                ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                    ->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")))
                ->orderBy('name')
                ->orderBy('id')
                ->paginate($filters['per_page'] ?? 25)
        );
    }

    public function store(StoreMemberRequest $request, InviteMember $invite): MemberResource
    {
        return new MemberResource($invite($request->validated(), $request->user())->load('roles'));
    }

    public function show(Request $request, User $member): MemberResource
    {
        abort_unless($request->user()?->can('administration.member.view'), 403);

        return new MemberResource($member->load('roles'));
    }

    public function update(UpdateMemberRequest $request, User $member, UpdateMember $update): MemberResource
    {
        return new MemberResource($update($member, $request->validated(), $request->user())->load('roles'));
    }

    public function access(UpdateMemberAccessRequest $request, User $member, SyncMemberAccess $sync): MemberResource
    {
        return new MemberResource($sync($member, $request->validated(), $request->user())->load('roles'));
    }

    public function suspend(Request $request, User $member, ChangeMemberStatus $change): MemberResource
    {
        abort_unless($request->user()?->can('administration.member.manage'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return new MemberResource($change($member, MemberStatus::Suspended, $data['reason'], $request->user())->load('roles'));
    }

    public function reactivate(Request $request, User $member, ChangeMemberStatus $change): MemberResource
    {
        abort_unless($request->user()?->can('administration.member.manage'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return new MemberResource($change($member, MemberStatus::Active, $data['reason'], $request->user())->load('roles'));
    }

    public function resend(Request $request, User $member): JsonResponse
    {
        abort_unless($request->user()?->can('administration.member.manage'), 403);
        abort_unless($member->status === MemberStatus::Invited, 422);
        $member->forceFill(['invited_at' => now(), 'invited_by' => $request->user()->id])->save();
        $member->notify(new MemberInvitation(Password::broker()->createToken($member)));
        activity()->performedOn($member)->causedBy($request->user())->log('member invitation resent');

        return response()->json(status: 204);
    }

    public function sendPasswordReset(Request $request, User $member): JsonResponse
    {
        abort_unless($request->user()?->can('administration.member.manage'), 403);
        abort_unless($member->status === MemberStatus::Active, 422);

        $status = Password::sendResetLink(['email' => $member->email]);
        if ($status !== Password::RESET_LINK_SENT) {
            return response()->json(['message' => __($status)], 422);
        }

        activity()->performedOn($member)->causedBy($request->user())->log('member password reset link sent');

        return response()->json(['message' => 'Password reset link sent.']);
    }

    public function issueTemporaryPassword(Request $request, User $member, IssueTemporaryMemberPassword $issue): JsonResponse
    {
        abort_unless($request->user()->can('administration.member.manage') && $request->user()->can('administration.access.assign'), 403);
        abort_if($request->user()->is($member), 403, 'You cannot reset your own password here.');
        abort_if($member->status === MemberStatus::Suspended, 422, 'Reactivate this user before issuing a password.');
        abort_unless($member->getAllPermissions()->pluck('name')->diff($request->user()->getAllPermissions()->pluck('name'))->isEmpty(), 403, 'You cannot issue credentials for a member with capabilities you do not hold.');

        $password = $issue($member, $request->user());

        return response()->json(['data' => ['temporary_password' => $password]])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    public function history(Request $request, User $member): JsonResponse
    {
        abort_unless($request->user()?->can('administration.member.view'), 403);

        $entries = Activity::query()
            ->where('company_id', $member->company_id)
            ->where('subject_type', User::class)
            ->where('subject_id', $member->id)
            ->whereIn('description', [
                'member created', 'member invited', 'member invitation resent', 'member details changed', 'member access changed',
                'member status changed', 'member password reset link sent', 'member temporary password issued',
            ])
            ->with('causer')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Activity $activity): array => [
                'id' => $activity->id,
                'description' => $activity->description,
                'actor' => $activity->causer?->getAttribute('name'),
                'reason' => $activity->properties->get('reason'),
                'created_at' => $activity->created_at?->toISOString(),
            ]);

        return response()->json(['data' => $entries]);
    }
}
