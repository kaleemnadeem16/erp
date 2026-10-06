<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Concerns;

use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Actions\SaveRole;
use App\Modules\Administration\Actions\SyncMemberAccess;
use App\Support\CompanyContext;

/**
 * What an administrator does in Access Administration, for demo data: build a company role from registered capabilities and give it
 * to a persona, through the same Actions the screens call. Create-once: an existing role is never edited, and a persona is given its
 * role only while it holds no access at all, so a rerun never undoes a change made in the screen. Needs EnsuresDemoEnvironment.
 */
trait CreatesDemoRolePersonas
{
    /** @param  list<string>  $permissions */
    private function rolePersona(User $admin, string $roleName, string $description, array $permissions, string $email, string $name): void
    {
        $company = app(CompanyContext::class)->id();
        $role = Role::query()->where('team_id', $company)->where('name', $roleName)->first()
            ?? app(SaveRole::class)(null, [
                'name' => $roleName,
                'description' => $description,
                'permissions' => $permissions,
                'reason' => 'Demo: an example role — a real company builds its own from the registered capabilities.',
            ], $admin);

        $member = $this->demoMember($email, $name);
        if ($member->roles()->doesntExist() && $member->permissions()->doesntExist()) {
            app(SyncMemberAccess::class)($member, [
                'role_ids' => [$role->id],
                'direct_permissions' => [],
                'reason' => "Demo persona: {$name}.",
            ], $admin);
        }
    }
}
