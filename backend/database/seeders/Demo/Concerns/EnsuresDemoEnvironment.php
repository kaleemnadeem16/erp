<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Concerns;

use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use RuntimeException;

trait EnsuresDemoEnvironment
{
    /** Create an active password-backed demo member once; preserve credentials and lifecycle on reruns. */
    private function demoMember(string $email, string $name): User
    {
        $this->ensureDemoEnvironment();
        $member = User::query()->firstOrCreate(['email' => $email], [
            'name' => $name, 'password' => config('demo.user.password'), 'status' => MemberStatus::Active,
        ]);
        // PostgreSQL defaults (including credential state) are not hydrated by an Eloquent insert.
        // Reload persisted state; never reactivate or bypass a temporary-password requirement.
        $member->refresh();
        if ($member->status !== MemberStatus::Active || $member->must_change_password) {
            throw new RuntimeException('Demo approval members must complete the normal activation/password flow before seeding.');
        }
        if ($member->email_verified_at === null) {
            $member->forceFill(['email_verified_at' => now()])->save();
        }

        return $member;
    }

    private function ensureDemoEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo data may only be seeded in local or testing environments.');
        }
    }
}
