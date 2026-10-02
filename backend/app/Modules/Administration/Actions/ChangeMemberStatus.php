<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use Illuminate\Support\Facades\DB;

final class ChangeMemberStatus
{
    public function __invoke(User $member, MemberStatus $status, string $reason, User $actor): User
    {
        if (! $actor->can('administration.member.manage')) {
            throw new DomainInvariantException('The actor cannot manage members.', 'status');
        }
        if ($actor->is($member)) {
            throw new DomainInvariantException('You cannot suspend or reactivate your own account.', 'status');
        }
        if ($status === MemberStatus::Invited) {
            throw new DomainInvariantException('Invitation status is managed by the invitation workflow.', 'status');
        }
        if ($status === MemberStatus::Active && $member->status !== MemberStatus::Suspended) {
            throw new DomainInvariantException('Only suspended members can be reactivated.', 'status');
        }

        return DB::transaction(function () use ($member, $status, $reason, $actor): User {
            $before = $member->status->value;
            $member->forceFill(['status' => $status, 'suspended_at' => $status === MemberStatus::Suspended ? now() : null, 'suspended_by' => $status === MemberStatus::Suspended ? $actor->id : null])->save();
            if ($status === MemberStatus::Suspended) {
                $member->tokens()->delete();
                DB::table('sessions')->where('user_id', $member->id)->delete();
            }
            activity()->performedOn($member)->causedBy($actor)->withProperties(['reason' => $reason, 'before' => $before, 'after' => $status->value])->log('member status changed');

            return $member->refresh();
        });
    }
}
