<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Support\FullAccessMandates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Server-operated evidence only: stored grant paths are not an activation or scope-adapter proof. */
final class AuditQuotationAccess extends Command
{
    protected $signature = 'sales:audit-quotation-access {--company= : Required company ID to inspect}';

    protected $description = 'Read-only quotation role/direct/mandate paths and unrestricted-scope flags; grants nothing';

    public function handle(): int
    {
        $company = filter_var($this->option('company'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($company === false) {
            $this->components->error('An explicit positive --company ID is required.');

            return self::FAILURE;
        }
        $report = DB::transaction(function () use ($company): ?array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            if (! DB::table('companies')->where('id', $company)->exists()) {
                return null;
            }
            $permissions = DB::table('permissions')->where('guard_name', 'web')->where(function ($query): void {
                $query->where('name', 'like', 'sales.quotation.%')->orWhere('name', 'like', 'sales.document.%')->orWhere('name', 'like', 'sales.delivery.%');
            })->orderBy('name')->pluck('name', 'id');
            $roles = DB::table('roles')->where('team_id', $company)->where('guard_name', 'web')->orderBy('id')->get(['id', 'name']);
            $rolePermissions = DB::table('role_has_permissions')->whereIn('role_id', $roles->pluck('id'))->whereIn('permission_id', $permissions->keys())->orderBy('permission_id')->get()->groupBy('role_id');
            $members = DB::table('users')->where('company_id', $company)->orderBy('id')->get(['id', 'name', 'email', 'status']);
            $morph = (new User)->getMorphClass();
            $assignments = DB::table('model_has_roles')->where('team_id', $company)->where('model_type', $morph)->whereIn('model_id', $members->pluck('id'))->get()->groupBy('model_id');
            $direct = DB::table('model_has_permissions')->where('team_id', $company)->where('model_type', $morph)->whereIn('model_id', $members->pluck('id'))->whereIn('permission_id', $permissions->keys())->get()->groupBy('model_id');
            $limits = DB::table('access_scope_limits')->where('company_id', $company)->get()->groupBy('user_id');
            $units = DB::table('organisation_unit_members as m')->join('organisation_units as u', fn ($join) => $join->on('u.company_id', '=', 'm.company_id')->on('u.id', '=', 'm.unit_id'))
                ->where('m.company_id', $company)->orderBy('u.id')->get(['m.user_id', 'u.id', 'u.code', 'u.status'])->groupBy('user_id');
            $mandates = array_values(array_filter((array) config('access.full_access_mandates', []), fn ($row): bool => is_array($row) && (int) ($row['company_id'] ?? 0) === $company));
            $people = [];
            foreach ($members as $member) {
                $paths = [];
                $memberLimits = collect($limits->get($member->id, []));
                $heldRoles = collect($assignments->get($member->id, []))->pluck('role_id');
                foreach ($roles->whereIn('id', $heldRoles) as $role) {
                    $limit = $memberLimits->first(fn ($row): bool => $row->role_id !== null && (int) $row->role_id === (int) $role->id);
                    foreach (collect($rolePermissions->get($role->id, [])) as $grant) {
                        $paths[] = ['kind' => 'role', 'role_id' => (int) $role->id, 'permission' => $permissions->get($grant->permission_id), 'scope' => $limit->scope ?? 'ALL'];
                    }
                }
                foreach (collect($direct->get($member->id, [])) as $grant) {
                    $permission = $permissions->get($grant->permission_id);
                    $limit = $memberLimits->first(fn ($row): bool => $row->role_id === null && $row->permission === $permission);
                    $paths[] = ['kind' => 'direct', 'role_id' => null, 'permission' => $permission, 'scope' => $limit->scope ?? 'ALL'];
                }
                $potential = [];
                foreach ($mandates as $mandate) {
                    $roleId = (int) ($mandate['role_id'] ?? 0);
                    $from = strtotime((string) ($mandate['effective_from'] ?? ''));
                    $potential[] = ['role_id' => $roleId, 'mandate' => (string) ($mandate['mandate'] ?? ''),
                        'approval_reference' => (string) ($mandate['approval_reference'] ?? ''), 'effective_from' => (string) ($mandate['effective_from'] ?? ''),
                        'generic_mandate_qualifies' => $heldRoles->contains($roleId) && $roles->contains('id', $roleId)
                            && ($mandate['mandate'] ?? '') === FullAccessMandates::ALL && trim((string) ($mandate['approval_reference'] ?? '')) !== '' && $from !== false && $from <= time(),
                        'quotation_rollout_approved' => false];
                }
                $people[] = ['id' => (int) $member->id, 'name' => $member->name, 'email' => $member->email, 'status' => $member->status,
                    'grant_paths' => $paths, 'unrestricted_paths' => array_values(array_filter($paths, fn (array $path): bool => $path['scope'] === 'ALL')),
                    'units' => collect($units->get($member->id, []))->map(fn ($unit): array => ['id' => (int) $unit->id, 'code' => $unit->code, 'status' => $unit->status])->all(),
                    'configured_mandate_paths' => $potential];
            }
            $catalogue = array_values(array_map(fn ($duty): array => ['name' => $duty->name, 'status' => $duty->status->value],
                array_filter(SalesDutyManifest::shipped()->all(), fn ($duty): bool => str_starts_with($duty->name, 'sales.quotation.') || str_starts_with($duty->name, 'sales.document.') || str_starts_with($duty->name, 'sales.delivery.'))));

            return ['company_id' => $company, 'production_activation' => 'BLOCKED', 'shipped_duties' => $catalogue,
                'roles' => $roles->map(fn ($role): array => ['id' => (int) $role->id, 'name' => $role->name,
                    'quotation_permissions' => collect($rolePermissions->get($role->id, []))->map(fn ($row) => $permissions->get($row->permission_id))->values()->all()])->all(),
                'members' => $people, 'notes' => [
                    'Stored paths do not imply an ACTIVE duty or supported scope. Suspended members are included for review.',
                    'One unrestricted conferring path defeats an OWN limit for the same duty. Do not infer scope from role titles.',
                    'Generic mandate qualification is not reviewed quotation company/scope approval and grants nothing.',
                    'No assignment, registration, epoch change or activation is performed. No credentials or tokens are read.',
                ]];
        });
        if ($report === null) {
            $this->components->error('The selected company does not exist.');

            return self::FAILURE;
        }
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
