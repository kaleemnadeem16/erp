<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\Models\Company;
use App\Support\CompanyContext;
use RuntimeException;

/**
 * The one check every demo seeder passes before it writes (issue #100, ADR 0129).
 *
 * - Environment: never production; otherwise `local`, `testing`, or a deployment that sets `DEMO_MODE=true`.
 * - Company: only the designated demo company ({@see self::COMPANY_ID}). The company is passed in, and must also be the current company and exist.
 *
 * Seeders are create-only: they never update, delete or rebuild what is already there (see docs/architecture/environments.md).
 */
final class DemoCompanyGuard
{
    /** The only company demo data may be written to. Production data for any other company is never touched. */
    public const COMPANY_ID = 1;

    public const ENVIRONMENT_MESSAGE = 'Demo data may only be seeded in local or testing environments.';

    public static function allowedEnvironment(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        return app()->environment(['local', 'testing']) || config('demo.mode') === true;
    }

    public static function assertEnvironment(): void
    {
        if (! self::allowedEnvironment()) {
            throw new RuntimeException(self::ENVIRONMENT_MESSAGE);
        }
    }

    /** Refuses unless the environment is allowed and $companyId is the existing, current demo company. Returns the verified id. */
    public static function assertCompany(int $companyId): int
    {
        self::assertEnvironment();
        if ($companyId !== self::COMPANY_ID
            || app(CompanyContext::class)->id() !== $companyId
            || ! Company::query()->whereKey($companyId)->exists()) {
            throw new RuntimeException('Demo data may only be seeded into the designated demo company.');
        }

        return $companyId;
    }

    /** The verified demo company for the current context. */
    public static function verifiedCompanyId(): int
    {
        return self::assertCompany(app(CompanyContext::class)->id());
    }
}
