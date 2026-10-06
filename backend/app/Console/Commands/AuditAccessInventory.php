<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Estimating\Support\RecipeReadAccess;
use App\Modules\Inventory\Support\NegativeStockReadAccess;
use App\Modules\Inventory\Support\PhysicalCountReadAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Server-operated staging evidence, never an assignment/backfill command. No secrets or token abilities are emitted. */
final class AuditAccessInventory extends Command
{
    protected $signature = 'erp:audit-access-inventory';

    protected $description = 'Read-only JSON inventory of company members, roles, direct grants and token actors for audit A1–A9';

    public const CAPABILITIES = [
        'A1' => ['currency.exchange-rate.manage'],
        'A2' => ['accounting.chart-of-account.manage'],
        'A3' => ['parties.party.manage', 'parties.customer-account.manage', 'parties.supplier-account.manage', 'parties.supplier-site.manage', 'parties.credit-limit.view', 'parties.credit-limit.manage', 'parties.payment-terms.manage'],
        'A4' => ['accounting.account-mapping.view', 'accounting.account-mapping.manage'], // ADR 0084.
        'A5' => ['approvals.document.technical-review'], // ADR 0085 optional specialist duty; commercial approval stays subject-owned.
        'A6' => ['inventory.availability.view', 'inventory.stock.view', 'inventory.movement.view'],
    ];

    /**
     * Reads that were open to every signed-in user and now need any one of a list of capabilities (audit A8, A9). The lists come from the
     * gates themselves, so this report cannot drift from what the application enforces. Unlike CAPABILITIES (every capability a role
     * might need), these are any-of rules, so a member is flagged only when they hold none of them.
     */
    public const READ_GATES = [
        'A8-negative-stock-override-requests' => NegativeStockReadAccess::PERMISSIONS,
        'A8-physical-counts' => PhysicalCountReadAccess::PERMISSIONS,
        'A9-recipes' => RecipeReadAccess::PERMISSIONS,
    ];

    public function handle(): int
    {
        $report = DB::transaction(function (): array {
            // A consistent snapshot; the database rejects any accidental mutation in this command.
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $companies = DB::table('companies')->select('id', 'name')->orderBy('id')->get();
            $members = DB::table('users')->select('id', 'company_id', 'name', 'email', 'status')->orderBy('id')->get();
            $roles = DB::table('roles')->select('id', 'team_id', 'name', 'guard_name')->orderBy('id')->get();
            $permissions = DB::table('permissions')->select('id', 'name', 'guard_name')->get()->keyBy('id');
            $rolePermissions = DB::table('role_has_permissions')->get()->groupBy('role_id');
            $roleAssignments = DB::table('model_has_roles')->get();
            $directGrants = DB::table('model_has_permissions')->get();
            // Record scope (ADR 0089): which assignments are limited to "only my units", and which units each person belongs to.
            $scopeLimits = DB::table('access_scope_limits')->get()->groupBy('user_id');
            $unitNames = DB::table('organisation_units')->pluck('name', 'id');
            $unitMembers = DB::table('organisation_unit_members')->get()->groupBy('user_id');
            $tokens = DB::table('personal_access_tokens')->select('id', 'tokenable_type', 'tokenable_id', 'name', 'created_at', 'last_used_at', 'expires_at')->orderBy('id')->get();
            $userType = (new User)->getMorphClass();
            $required = array_merge(...array_values(self::CAPABILITIES));
            $companyReports = [];
            foreach ($companies as $company) {
                $companyRoles = $roles->where('team_id', $company->id)->map(function ($role) use ($rolePermissions, $permissions): array {
                    $names = collect($rolePermissions->get($role->id, []))->map(fn ($row) => $permissions->get($row->permission_id)?->name)->filter()->sort()->values()->all();

                    return ['id' => $role->id, 'name' => $role->name, 'guard' => $role->guard_name, 'permissions' => $names];
                })->values();
                $companyMembers = [];
                foreach ($members->where('company_id', $company->id) as $member) {
                    $assignments = $roleAssignments->where('model_type', $userType)->where('model_id', $member->id);
                    $ownAssignments = $assignments->where('team_id', $company->id);
                    $assigned = $companyRoles->whereIn('id', $ownAssignments->pluck('role_id'))->values();
                    $grants = $directGrants->where('model_type', $userType)->where('model_id', $member->id);
                    $direct = $grants->where('team_id', $company->id)->map(fn ($row) => $permissions->get($row->permission_id))->filter(fn ($permission) => $permission?->guard_name === 'web')->pluck('name')->sort()->values();
                    $effective = $direct->merge($assigned->where('guard', 'web')->flatMap(fn ($role) => $role['permissions']))->unique()->sort()->values();
                    $memberTokens = $tokens->where('tokenable_type', $userType)->where('tokenable_id', $member->id)->map(fn ($token) => [
                        'id' => $token->id, 'name' => $token->name, 'created_at' => $token->created_at,
                        'last_used_at' => $token->last_used_at, 'expires_at' => $token->expires_at,
                    ])->values()->all();
                    $companyMembers[] = [
                        'id' => $member->id, 'name' => $member->name, 'email' => $member->email, 'status' => $member->status,
                        'roles' => $assigned->all(), 'direct_grants' => $direct->all(), 'effective_capabilities' => $effective->all(),
                        'missing_audit_capabilities' => array_values(array_diff($required, $effective->all())),
                        // Reads that were open to every signed-in user and that this member can no longer make (holds none of the gate's capabilities).
                        'read_access_lost' => collect(self::READ_GATES)->filter(fn (array $anyOf): bool => array_intersect($anyOf, $effective->all()) === [])->keys()->values()->all(),
                        'scope_limits' => collect($scopeLimits->get($member->id, []))->map(fn ($limit): array => ['role_id' => $limit->role_id, 'permission' => $limit->permission, 'scope' => $limit->scope])->values()->all(),
                        'organisation_units' => collect($unitMembers->get($member->id, []))->map(fn ($m): array => ['id' => $m->unit_id, 'name' => $unitNames->get($m->unit_id)])->values()->all(),
                        'service_actor_classification' => 'OWNER_CONFIRMATION_REQUIRED', 'bearer_tokens' => $memberTokens,
                        'foreign_team_role_assignments' => $assignments->where('team_id', '!=', $company->id)->values()->all(),
                        'foreign_team_direct_grants' => $grants->where('team_id', '!=', $company->id)->map(fn ($row) => ['team_id' => $row->team_id, 'permission_id' => $row->permission_id])->values()->all(),
                        'unresolved_role_ids' => $ownAssignments->pluck('role_id')->diff($assigned->pluck('id'))->values()->all(),
                    ];
                }
                $readGateSummary = [];
                foreach (array_keys(self::READ_GATES) as $gate) {
                    $readGateSummary[$gate] = ['members_without_access' => collect($companyMembers)->filter(fn (array $m): bool => in_array($gate, $m['read_access_lost'], true))->pluck('id')->values()->all()];
                }
                $companyReports[] = ['id' => $company->id, 'name' => $company->name, 'roles' => $companyRoles->all(), 'members' => $companyMembers, 'read_gate_summary' => $readGateSummary];
            }

            return [
                'audit_capabilities' => self::CAPABILITIES,
                'read_gates' => self::READ_GATES,
                'notes' => [
                    'Missing capabilities are review flags, not recommended grants. Verify intended duty with the company owner.',
                    'scope_limits lists the assignments limited to "only my units" (ADR 0089) and organisation_units the units each person belongs to; a limited person who belongs to no unit reaches no record. Only inventory stock, ledger and counts apply scope so far (RecordScopeCoverageTest lists the rest).',
                    'read_access_lost lists reads that were open to every signed-in user (A8 override requests and physical counts, A9 recipes) and that the member can no longer make because they hold none of the gate\'s capabilities. Each gate is an any-of rule; decide per person whether they need the read, then grant a narrow capability through a role. Active and suspended members are both listed.',
                    'A3/A4 use ADR 0084 field capabilities; field management supplies minimum field read. A5 still needs request-specific checks.',
                    'No service-account marker exists. Owners must classify every member, including service actors without tokens.',
                    'Token expiry shown here is per-token only; Sanctum global expiration also applies.',
                ],
                'sanctum_expiration_minutes' => config('sanctum.expiration'),
                'companies' => $companyReports,
                'unresolved_token_actors' => $tokens->filter(fn ($token) => $token->tokenable_type !== $userType || ! $members->contains('id', $token->tokenable_id))->map(fn ($token) => ['token_id' => $token->id, 'actor_type' => $token->tokenable_type, 'actor_id' => $token->tokenable_id])->values()->all(),
                'unresolved_assignment_actors' => $roleAssignments->merge($directGrants)->filter(fn ($row) => $row->model_type !== $userType || ! $members->contains('id', $row->model_id))->map(fn ($row) => ['actor_type' => $row->model_type, 'actor_id' => $row->model_id, 'team_id' => $row->team_id])->unique()->values()->all(),
            ];
        });
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
