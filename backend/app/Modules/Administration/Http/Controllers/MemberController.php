<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Administration\Actions\ChangeMemberStatus;
use App\Modules\Administration\Actions\InviteMember;
use App\Modules\Administration\Actions\SyncMemberAccess;
use App\Modules\Administration\Http\Requests\StoreMemberRequest;
use App\Modules\Administration\Http\Requests\UpdateMemberAccessRequest;
use App\Modules\Administration\Http\Resources\MemberResource;
use App\Modules\Administration\Notifications\MemberInvitation;
use App\Modules\Administration\Support\MemberStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class MemberController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->can('administration.member.view'), 403);

        return MemberResource::collection(User::query()->with('roles')->orderBy('name')->paginate(25));
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

        return response()->json(status: 204);
    }
}
