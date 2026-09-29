<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Notifications\MemberInvitation;
use App\Modules\Administration\Support\AccessAssignmentGuard;
use App\Modules\Administration\Support\MemberStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final class InviteMember
{
    public function __construct(private readonly AccessAssignmentGuard $guard) {}

    public function __invoke(array $attributes, User $actor): User
    {
        if (! $actor->can('administration.member.manage')) {
            throw new DomainInvariantException('The actor cannot invite members.', 'email');
        }
        if (($attributes['role_ids'] ?? []) !== [] || ($attributes['direct_permissions'] ?? []) !== []) {
            if (! $actor->can('administration.access.assign')) {
                throw new DomainInvariantException('The actor cannot assign access.', 'role_ids');
            }
        }
        $access = $this->guard->validate($actor, $attributes['role_ids'] ?? [], $attributes['direct_permissions'] ?? []);
        $member = DB::transaction(function () use ($attributes, $actor, $access): User {
            $member = User::query()->create(['name' => $attributes['name'], 'email' => $attributes['email'], 'password' => Str::random(64), 'status' => MemberStatus::Invited, 'invited_at' => now(), 'invited_by' => $actor->id]);
            $member->syncRoles($access['roles']);
            $member->syncPermissions($access['permissions']);
            activity()->performedOn($member)->causedBy($actor)->withProperties(['reason' => $attributes['reason'] ?? 'Initial invitation', 'roles' => collect($access['roles'])->pluck('name'), 'direct_permissions' => $access['permissions']])->log('member invited');

            return $member;
        });
        $member->notify(new MemberInvitation(Password::broker()->createToken($member)));

        return $member->refresh();
    }
}
