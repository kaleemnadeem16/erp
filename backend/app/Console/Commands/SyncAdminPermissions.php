<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\CompanyContext;
use App\Support\PermissionRegistry;
use Illuminate\Console\Command;

/**
 * Keeps a designated administrator's permissions in step with the registered
 * vocabulary after every deploy.
 *
 * `erp:create-admin --permissions=all` only grants the permissions that were
 * registered at the moment that user was created — a one-time snapshot, not a
 * standing wildcard (see authorization.md's "Safe rollout rule"). Every later
 * feature slice that ships its own permissions (as most do) leaves that
 * administrator behind unless something re-grants the new names. Local
 * development never hits this: StockDemoSeeder syncs the demo login to the
 * full registry on every `demo:seed` run. Production had no equivalent step —
 * the deploy job only ran `permission:register`, which creates the new
 * Permission rows but grants them to nobody — so the production admin
 * silently fell further behind the vocabulary with every release since the
 * account was first created (found 2026-09-26: the Material Preview slice's
 * new `estimating.*` permissions were registered but never granted).
 */
final class SyncAdminPermissions extends Command
{
    protected $signature = 'erp:sync-admin-permissions
        {email : The administrator sign-in email to keep fully permissioned}';

    protected $description = 'Grant an existing administrator every currently registered permission (safe to re-run every deploy)';

    public function handle(CompanyContext $companyContext, PermissionRegistry $permissions): int
    {
        $email = strtolower((string) $this->argument('email'));

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->components->error("No user with the email {$email} exists — nothing to sync.");

            return self::FAILURE;
        }

        $permissions->registerForTeam($companyContext->id());

        $before = $user->getAllPermissions()->pluck('name')->all();
        $user->syncPermissions($permissions->all());
        $after = $user->getAllPermissions()->pluck('name')->sort()->values();

        $added = array_values(array_diff($after->all(), $before));

        if ($added === []) {
            $this->components->info("{$user->email} already held all {$after->count()} registered permission(s).");
        } else {
            $this->components->info(
                "{$user->email} now holds all {$after->count()} registered permission(s) — granted ".
                count($added).' new: '.implode(', ', $added)
            );
        }

        return self::SUCCESS;
    }
}
