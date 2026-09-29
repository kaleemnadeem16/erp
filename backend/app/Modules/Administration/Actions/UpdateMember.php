<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateMember
{
    public function __invoke(User $member, array $attributes, User $actor): User
    {
        if (! $actor->can('administration.member.manage')) {
            throw new DomainInvariantException('The actor cannot manage members.', 'name');
        }

        return DB::transaction(function () use ($member, $attributes, $actor): User {
            $before = $member->only(['name', 'email']);
            $member->fill($attributes)->save();
            activity()
                ->performedOn($member)
                ->causedBy($actor)
                ->withProperties([
                    'before' => $before,
                    'after' => $member->only(['name', 'email']),
                ])
                ->log('member details changed');

            return $member->refresh();
        });
    }
}
