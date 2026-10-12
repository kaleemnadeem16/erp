<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Does an approved, machine-verifiable mandate cover this person's automatic "every registered capability" grant? (ADR 0114)
 *
 * A mandate attests that an existing company role is genuinely full-access; it does not turn a role into one. It names the company, the existing role
 * id, the explicit mandate, an approval reference and an effective date, and the person must actually hold that role in that company. Anything missing,
 * foreign or future-dated covers nobody, so the safe answer is always "not covered".
 */
final class FullAccessMandates
{
    public const ALL = 'ALL_REGISTERED_CAPABILITIES';

    public function covers(User $user): bool
    {
        foreach ((array) config('access.full_access_mandates', []) as $mandate) {
            if (! is_array($mandate) || ! $this->qualifies($mandate, $user)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $mandate */
    private function qualifies(array $mandate, User $user): bool
    {
        if (($mandate['mandate'] ?? null) !== self::ALL || trim((string) ($mandate['approval_reference'] ?? '')) === '') {
            return false;
        }
        if ((int) ($mandate['company_id'] ?? 0) !== (int) $user->company_id || (int) ($mandate['role_id'] ?? 0) < 1) {
            return false;
        }
        $from = strtotime((string) ($mandate['effective_from'] ?? ''));
        if ($from === false || $from > time()) {
            return false;
        }
        $role = Role::query()->where('team_id', $user->company_id)->whereKey((int) $mandate['role_id'])->first();
        if ($role === null) {
            return false;
        }

        return DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))
            ->where('role_id', $role->id)->where('model_id', $user->id)->where('model_type', $user->getMorphClass())
            ->where(config('permission.column_names.team_foreign_key', 'team_id'), $user->company_id)->exists();
    }
}
