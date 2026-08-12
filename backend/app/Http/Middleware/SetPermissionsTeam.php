<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves spatie/laravel-permission's current "team" for every request. Team is a
 * distinct dimension from company — it's the seam department-level scoping attaches
 * to later (docs/architecture/authorization.md) — but until departments exist, every
 * row belongs to one team, same as one company, so this reads from CompanyContext
 * rather than maintaining a second hardcoded id.
 *
 * PermissionRegistrar, not the PermissionsTeamResolver contract, is what's actually
 * container-bound — the registrar builds its own resolver internally and delegates to
 * it, so setting the id anywhere else wouldn't be seen by permission checks.
 */
class SetPermissionsTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(
            app(CompanyContext::class)->id()
        );

        return $next($request);
    }
}
