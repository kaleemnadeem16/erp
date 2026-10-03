<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Models\User;
use App\Modules\Estimating\Models\OverheadPolicy;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

/**
 * Creates a DRAFT policy version. Every row is a governed change — see
 * docs/domain/assemblies.md "Overhead allocation" — so `reason` and
 * `changed_by` (the acting user) are required on every row, not just on
 * later edits; there is no "current settings" row this is a delta from.
 */
final class CreateOverheadPolicy
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): OverheadPolicy
    {
        ActionAuthorization::require($actor, 'estimating.overhead-policy.manage', 'create an overhead policy');
        $actorId = $actor->id;

        return DB::transaction(fn () => OverheadPolicy::create([
            ...$attributes,
            'changed_by' => $actorId,
        ])->refresh());
    }
}
