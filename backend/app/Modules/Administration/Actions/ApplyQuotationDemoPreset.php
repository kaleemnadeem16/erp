<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Support\QuotationDemoPresets;
use App\Modules\Sales\Support\Authority\SalesDevPreview;
use App\Modules\Sales\Support\Quotation\QuotationFamilyScopeAdapter;
use App\Support\CompanyContext;
use App\Support\Demo\QuotationDemoBoundary;
use App\Support\PermissionRegistry;
use App\Support\Scope\RecordScope;
use App\Support\Scope\ResolvedScope;
use Illuminate\Support\Facades\DB;

/**
 * Switches the main demo login (ADR 0156/0159/0161) to one named quotation preset, acting AS the synthetic access operator so that SyncMemberAccess keeps refusing self-change and
 * granting what the grantor does not hold. Local preview only (`SalesDevPreview::enabled()`); designated demo Company 1 only; never issue-by-the-patch, never repair, never assign
 * delegation of approval. Idempotent: repeating a preset writes nothing. Returns the resulting rights so the caller can print exactly what the login now holds.
 */
final class ApplyQuotationDemoPreset
{
    public function __construct(private readonly SaveRole $saveRole, private readonly SyncMemberAccess $sync, private readonly CompanyContext $company, private readonly PermissionRegistry $registry) {}

    /** @return array{preset: string, about: string, email: string, rights: list<array{duty: string, held: bool, scope: string}>} */
    public function __invoke(string $preset, ?User $operator = null, ?User $main = null): array
    {
        $definition = QuotationDemoPresets::get($preset);
        if (! SalesDevPreview::enabled()) {
            throw new DomainInvariantException('Demo presets need the local preview: APP_ENV=local and SALES_DEV_QUOTATION_DUTIES=true. The quotation duties stay STAGED everywhere else.', 'preset');
        }
        QuotationDemoBoundary::assertCompany($this->company->id());
        $this->registry->registerForTeam($this->company->id());
        $operator ??= User::query()->where('email', QuotationDemoBoundary::OPERATOR_EMAIL)->first()
            ?? throw new DomainInvariantException('The synthetic access operator does not exist yet. Run demo:seed (QuotationAccessDemoSeeder creates it).', 'operator');
        $main ??= User::query()->where('email', QuotationDemoBoundary::MAIN_EMAIL)->first()
            ?? throw new DomainInvariantException('The main demo login does not exist.', 'email');

        DB::transaction(function () use ($definition, $operator, $main, $preset): void {
            $roleIds = [];
            $limits = [];
            if ($definition['role'] !== null) {
                $role = $this->role($definition['role'], $operator);
                $roleIds[] = (int) $role->id;
                if ($definition['scope'] !== 'ALL') {
                    $limits[] = ['role_id' => (int) $role->id, 'scope' => $definition['scope']];
                }
            }
            $this->sync->patchQuotationDemo($main, ['role_ids' => $roleIds, 'direct_permissions' => [], 'scope_limits' => $limits, 'reason' => "Demo preset: {$preset}"], $operator);
            $this->addons($main->refresh(), $definition['addons'], $operator, $preset);
        });

        return ['preset' => $preset, 'about' => $definition['about'], 'email' => $main->email, 'rights' => $this->rights($main->refresh())];
    }

    /**
     * Gives a FRESH synthetic persona (no other rights) one preset's access role through the ordinary SyncMemberAccess as the operator. Create-only: a persona that already holds any role or
     * direct right is left exactly as it is. The caller has checked the demo boundary and the local preview.
     */
    public function provision(User $persona, string $preset, User $operator): bool
    {
        $definition = QuotationDemoPresets::get($preset);
        if ($definition['role'] === null || $persona->roles()->exists() || $persona->permissions()->exists()) {
            return false;
        }
        $role = $this->role($definition['role'], $operator);
        $limits = $definition['scope'] === 'ALL' ? [] : [['role_id' => (int) $role->id, 'scope' => $definition['scope']]];
        $this->sync->__invoke($persona, ['role_ids' => [(int) $role->id], 'direct_permissions' => [], 'scope_limits' => $limits, 'reason' => "Demo persona preset: {$preset}"], $operator);

        return true;
    }

    /** Created once by the real SaveRole as the operator; a hand-edited role is refused rather than overwritten. */
    private function role(string $name, User $operator): Role
    {
        $duties = QuotationDemoPresets::roles()[$name];
        $role = Role::query()->where('team_id', $this->company->id())->where('name', $name)->with('permissions')->first();
        if ($role === null) {
            return $this->saveRole->__invoke(null, ['name' => $name, 'description' => 'Synthetic quotation demo role (ADR 0161). Quotation duties only.', 'permissions' => $duties, 'reason' => 'Demo preset role definition'], $operator);
        }
        $held = $role->permissions->pluck('name')->sort()->values()->all();
        $want = collect($duties)->sort()->values()->all();
        if ($held !== $want) {
            throw new DomainInvariantException("The demo role \"{$name}\" was changed by hand (it holds ".implode(', ', $held).'). Rename or delete it, then apply the preset again.', 'role_ids');
        }

        return $role;
    }

    /**
     * Workflow add-ons: direct grants on top of the access role, kept in step with the preset. Local preview only, like `sales:dev-grant-quotation`; audited.
     *
     * @param  list<string>  $wanted
     */
    private function addons(User $main, array $wanted, User $operator, string $preset): void
    {
        $before = $main->getDirectPermissions()->pluck('name')->intersect(QuotationDemoPresets::ADDONS)->sort()->values()->all();
        $after = collect($wanted)->sort()->values()->all();
        if ($before === $after) {
            return;
        }
        $main->revokePermissionTo(array_values(array_diff($before, $after)));
        $main->givePermissionTo(array_values(array_diff($after, $before)));
        $main->forceFill(['access_updated_at' => now()])->save();
        activity()->performedOn($main)->causedBy($operator)->withProperties(['reason' => "Demo preset: {$preset}", 'before' => $before, 'after' => $after, 'decision' => 'ADR 0161'])->log('quotation demo workflow duties changed');
    }

    /** @return list<array{duty: string, held: bool, scope: string}> */
    public function rights(User $user): array
    {
        $held = $user->getAllPermissions()->pluck('name')->all();
        $rows = [];
        foreach ([...QuotationDemoBoundary::DUTIES, ...QuotationDemoPresets::ADDONS] as $duty) {
            $has = in_array($duty, $held, true);
            $rows[] = ['duty' => $duty, 'held' => $has, 'scope' => $has ? $this->describe(app(RecordScope::class)->resolveForResource($user, $duty, QuotationFamilyScopeAdapter::RESOURCE)) : '-'];
        }

        return $rows;
    }

    private function describe(ResolvedScope $scope): string
    {
        return match (true) {
            $scope->isAll() => 'ALL',
            $scope->isNone() => 'NONE',
            default => implode(' + ', array_filter([
                $scope->ownsRecords() ? 'OWN' : null,
                $scope->unitIds() !== [] ? 'UNITS('.implode(',', $scope->unitIds()).')' : null,
                $scope->allowsNamedDelegation() ? 'named shares' : null,
            ])),
        };
    }
}
