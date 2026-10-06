<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Support\AccessAssignmentGuard;
use App\Modules\Administration\Support\MemberStatus;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final class IssueTemporaryMemberPassword
{
    public function __construct(private readonly AccessAssignmentGuard $guard) {}

    public function __invoke(User $member, User $actor): string
    {
        $this->guard->assertCompany($actor);
        $this->guard->assertCompany($member);
        if (! $actor->can('administration.member.manage') || ! $actor->can('administration.access.assign')) {
            throw new DomainInvariantException('You cannot issue member credentials.', 'member');
        }
        if ($actor->is($member)) {
            throw new DomainInvariantException('You cannot issue your own temporary password.', 'member');
        }
        if ($member->getAllPermissions()->pluck('name')->diff($actor->getAllPermissions()->pluck('name'))->isNotEmpty()) {
            throw new DomainInvariantException('You cannot issue credentials for a member with capabilities you do not hold.', 'member');
        }

        return DB::transaction(function () use ($member, $actor): string {
            $member = User::query()->lockForUpdate()->findOrFail($member->id);
            if ($member->status === MemberStatus::Suspended) {
                throw new DomainInvariantException('Reactivate this user before issuing a password.', 'member');
            }

            $password = Str::random(32);
            $member->forceFill([
                'password' => $password,
                'status' => MemberStatus::Active,
                'must_change_password' => true,
                'credential_version' => $member->credential_version + 1,
            ])->save();
            $member->tokens()->delete();
            /** @var PasswordBroker $broker */
            $broker = Password::broker();
            $broker->deleteToken($member);
            activity()->performedOn($member)->causedBy($actor)->log('member temporary password issued');

            return $password;
        });
    }
}
