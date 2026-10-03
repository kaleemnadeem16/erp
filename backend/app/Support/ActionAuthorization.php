<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\User;

/**
 * The capability check an Action makes for itself, so a console command, job or future integration that calls it without HTTP is
 * stopped exactly as a request would be (docs/architecture/authorization.md, "Where checks live"). The Form Request still answers
 * early with a 403; this is the authoritative one and refuses with the domain error every Action already uses.
 */
final class ActionAuthorization
{
    /** @param  string|list<string>  $permissions  any one of them is enough */
    public static function require(User $actor, string|array $permissions, string $action): void
    {
        if (! $actor->hasAnyPermission((array) $permissions)) {
            throw new DomainInvariantException("The actor is not permitted to {$action}.");
        }
    }
}
