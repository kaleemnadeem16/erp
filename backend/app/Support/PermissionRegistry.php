<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use InvalidArgumentException;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permission vocabulary shipped by the application.
 *
 * Permission rows are tenant/team data, so registering the vocabulary is an
 * explicit deployment step rather than a migration. Demo data may consume
 * this registry, but production must not depend on the demo seeder.
 */
final class PermissionRegistry
{
    /** @var array<string, list<string>> */
    private array $permissionsByOwner = [];

    /** @var array<string, string> permission → why no automatic, all-capabilities grant may include it */
    private array $notAutomaticallyGranted = [];

    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /** @param list<string> $permissions */
    public function registerVocabulary(string $owner, array $permissions): void
    {
        $owner = trim($owner);

        if ($owner === '') {
            throw new InvalidArgumentException('A permission vocabulary owner is required.');
        }

        if (count($permissions) !== count(array_unique($permissions))) {
            throw new InvalidArgumentException("Permission vocabulary [{$owner}] contains duplicate names.");
        }

        foreach ($permissions as $permission) {
            if (! preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)+$/', $permission)) {
                throw new InvalidArgumentException("Permission [{$permission}] has an invalid name.");
            }

            foreach ($this->permissionsByOwner as $registeredOwner => $registered) {
                if ($registeredOwner !== $owner && in_array($permission, $registered, true)) {
                    throw new LogicException(
                        "Permission [{$permission}] is owned by both [{$registeredOwner}] and [{$owner}]."
                    );
                }
            }
        }

        sort($permissions);
        $this->permissionsByOwner[$owner] = $permissions;
    }

    /** @return list<string> */
    public function all(): array
    {
        $permissions = [];
        $vocabularies = $this->permissionsByOwner;
        ksort($vocabularies);

        foreach ($vocabularies as $ownedPermissions) {
            array_push($permissions, ...$ownedPermissions);
        }

        return $permissions;
    }

    /**
     * A sensitive duty that no automatic expansion of "every registered capability" may grant: an explicit, audited assignment (or a
     * verified full-access mandate, see FullAccessMandates) is the only way to hold it. Registration itself stays unfiltered.
     *
     * @param  list<string>  $permissions
     */
    public function excludeFromAutomaticGrants(array $permissions, string $reason): void
    {
        foreach ($permissions as $permission) {
            $this->notAutomaticallyGranted[$permission] = $reason;
        }
    }

    /** @return list<string> */
    public function notAutomaticallyGranted(): array
    {
        return array_keys($this->notAutomaticallyGranted);
    }

    /**
     * What an automatic "everything registered" grant gives this person: every registered capability except the excluded duties, which are
     * included only for someone covered by a verified full-access mandate. One predicate for every all-capabilities caller.
     *
     * @return list<string>
     */
    public function automaticGrants(?User $target = null): array
    {
        $covered = $target !== null && app(FullAccessMandates::class)->covers($target);

        return $covered ? $this->all() : array_values(array_diff($this->all(), $this->notAutomaticallyGranted()));
    }

    /**
     * Brings an administrator in step with the registry without revoking anything legitimately held: the automatic grants, plus any excluded duty the
     * person already holds directly (an explicit grant survives a re-sync).
     *
     * @return list<string> the permissions now granted
     */
    public function syncAutomatic(User $user): array
    {
        $held = $user->getDirectPermissions()->pluck('name')->all();
        $explicit = array_values(array_intersect($held, $this->notAutomaticallyGranted()));
        $names = array_values(array_unique([...$this->automaticGrants($user), ...$explicit]));
        $user->syncPermissions($names);

        return $names;
    }

    /** @return array<string, list<string>> */
    public function byOwner(): array
    {
        $vocabularies = $this->permissionsByOwner;
        ksort($vocabularies);

        return $vocabularies;
    }

    /**
     * Ensure every application permission exists for the supplied team.
     *
     * @return int Number of vocabulary entries processed.
     */
    public function registerForTeam(int $teamId): int
    {
        $this->registrar->setPermissionsTeamId($teamId);

        foreach ($this->all() as $permission) {
            Permission::findOrCreate($permission);
        }

        $this->registrar->forgetCachedPermissions();

        return count($this->all());
    }
}
