<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/** ADR 0084: preserve previous field-maintenance duties, not every former shared reader. */
return new class extends Migration
{
    private const GRANTS = [
        'parties.customer-account.manage' => ['parties.credit-limit.view', 'parties.credit-limit.manage', 'parties.payment-terms.manage', 'accounting.account-mapping.view', 'accounting.account-mapping.manage'],
        'parties.supplier-account.manage' => ['parties.payment-terms.manage', 'accounting.account-mapping.view', 'accounting.account-mapping.manage'],
        'inventory.item.manage' => ['accounting.account-mapping.view', 'accounting.account-mapping.manage'],
        'inventory.category.manage' => ['accounting.account-mapping.view', 'accounting.account-mapping.manage'],
    ];

    public function up(): void
    {
        $now = now();
        $names = array_unique(array_merge(...array_values(self::GRANTS)));
        foreach ($names as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }
        $ids = DB::table('permissions')->where('guard_name', 'web')->pluck('id', 'name');
        $team = config('permission.column_names.team_foreign_key', 'team_id');
        foreach (self::GRANTS as $trigger => $grants) {
            $triggerId = $ids->get($trigger);
            if ($triggerId === null) {
                continue;
            }
            $roles = DB::table('role_has_permissions')->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
                ->where('role_has_permissions.permission_id', $triggerId)->where('roles.guard_name', 'web')->pluck('roles.id');
            foreach ($roles as $roleId) {
                foreach ($grants as $name) {
                    DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids->get($name)]);
                }
            }
            $direct = DB::table('model_has_permissions')->where('permission_id', $triggerId)->get();
            foreach ($direct as $row) {
                foreach ($grants as $name) {
                    DB::table('model_has_permissions')->insertOrIgnore([
                        'permission_id' => $ids->get($name), 'model_type' => $row->model_type,
                        'model_id' => $row->model_id, $team => $row->{$team},
                    ]);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Retain assignments on rollback: original and manually added grants cannot be distinguished safely.
        // Rolling back the field enforcement restores the previous broader read behaviour without deleting grants.
    }
};
