<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Support\AccessAssignmentGuard;
use Illuminate\Support\Facades\DB;

final class UpdateMember
{
    public function __construct(private readonly AccessAssignmentGuard $guard) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(User $member, array $attributes, User $actor): User
    {
        $this->guard->assertCompany($actor);
        $this->guard->assertCompany($member);
        if (! $actor->can('administration.member.manage')) {
            throw new DomainInvariantException('The actor cannot manage members.', 'name');
        }

        return DB::transaction(function () use ($member, $attributes, $actor): User {
            $before = $member->only(['name', 'email']);
            $member->fill(array_intersect_key($attributes, array_flip(['name', 'email'])))->save();
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
