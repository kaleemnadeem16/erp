<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Estimating\Models\MaterialSelectionPolicy;
use App\Modules\Estimating\Support\MaterialSelectionPolicyStatus;
use App\Modules\Estimating\Support\MaterialSelectionScope;
use App\Modules\Estimating\Support\MaterialSelectionStrategy;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Support\ActionAuthorization;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Records the selection strategy a scope prefers (ADR 0071). A governed change:
 * it needs an actor and a written reason, keeps the previous row as history and
 * affects only previews made afterwards — a stored preview is never
 * recalculated. Passing a null strategy records "no preference here" (inherit),
 * which is how a setting is cleared without losing who cleared it and why.
 * Recording what is already in force writes nothing.
 */
final class SetMaterialSelectionPolicy
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function __invoke(MaterialSelectionScope $scope, ?int $scopeId, ?MaterialSelectionStrategy $strategy, string $reason, User $actor): MaterialSelectionPolicy
    {
        ActionAuthorization::require($actor, 'estimating.selection-policy.manage', 'set a material selection policy');
        $actorId = $actor->id;
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainInvariantException('Changing a selection strategy requires a reason.', 'reason');
        }
        if (($scope === MaterialSelectionScope::Company) !== ($scopeId === null)) {
            throw new DomainInvariantException('Only the company scope has no scope id, and every other scope needs one.', 'scope_id');
        }

        return DB::transaction(function () use ($scope, $scopeId, $strategy, $reason, $actorId): MaterialSelectionPolicy {
            // One lock per company serialises concurrent changes, so the one-active-row index is never raced.
            Company::query()->lockForUpdate()->findOrFail($this->companyContext->id());

            $this->assertScopeExists($scope, $scopeId);

            $active = MaterialSelectionPolicy::query()
                ->where('scope_type', $scope)
                ->when($scopeId === null, fn ($q) => $q->whereNull('scope_id'), fn ($q) => $q->where('scope_id', $scopeId))
                ->where('status', MaterialSelectionPolicyStatus::Active)
                ->first();

            if ($active !== null && $active->strategy === $strategy) {
                return $active;
            }
            if ($active === null && $strategy === null) {
                throw new DomainInvariantException('There is no stored strategy here to clear.', 'strategy');
            }

            $now = now();
            $active?->update(['status' => MaterialSelectionPolicyStatus::Superseded, 'effective_to' => $now]);

            return MaterialSelectionPolicy::query()->create([
                'scope_type' => $scope,
                'scope_id' => $scopeId,
                'strategy' => $strategy,
                'reason' => $reason,
                'status' => MaterialSelectionPolicyStatus::Active,
                'effective_from' => $now,
                'changed_by' => $actorId,
            ])->refresh();
        });
    }

    private function assertScopeExists(MaterialSelectionScope $scope, ?int $scopeId): void
    {
        $exists = match ($scope) {
            MaterialSelectionScope::Company => true,
            MaterialSelectionScope::ItemCategory => Category::query()->whereKey($scopeId)->exists(),
            MaterialSelectionScope::Item => Item::query()->whereKey($scopeId)->exists(),
        };
        if (! $exists) {
            throw new DomainInvariantException('The selected item or category does not exist.', 'scope_id');
        }
    }
}
