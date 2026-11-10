<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Concerns;

use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Support\Demo\DemoCompanyGuard;
use App\Support\Demo\DemoCredentials;
use RuntimeException;

trait EnsuresDemoEnvironment
{
    /** Create an active password-backed demo member once; preserve credentials and lifecycle on reruns. */
    private function demoMember(string $email, string $name): User
    {
        $this->ensureDemoEnvironment();
        $member = User::query()->firstOrCreate(['email' => $email], fn (): array => [
            'name' => $name, 'password' => DemoCredentials::password(), 'status' => MemberStatus::Active,
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

    /** The single guard every demo seeder passes first: allowed environment, verified demo company, create-only (DemoCompanyGuard). */
    private function ensureDemoEnvironment(): void
    {
        DemoCompanyGuard::verifiedCompanyId();
    }

    /** The verified demo company id; seeders use this instead of a literal company id. */
    private function demoCompanyId(): int
    {
        return DemoCompanyGuard::verifiedCompanyId();
    }
}
