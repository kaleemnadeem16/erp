<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR 0087 (Q35): reading everyone's approval spending limits and the free-text reasons on decisions now needs its own capability
 * (`approvals.authority.view`, `approvals.reason.view`) instead of coming with `approvals.history.view`. So that nobody loses a read
 * on release, every role and person holding `approvals.history.view` today is given both — additively, idempotently, and never a
 * broad role. The owner can then revoke either per person through Access Administration; the access-inventory command lists who has
 * what. A role or direct grant is copied only where the history capability is already held, in the same company (team).
 */
return new class extends Migration
{
    private const NEW = ['approvals.authority.view', 'approvals.reason.view'];

    public function up(): void
    {
        $now = now();
        foreach (self::NEW as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }
        $history = DB::table('permissions')->where('name', 'approvals.history.view')->where('guard_name', 'web')->value('id');
        if ($history !== null) {
            $roles = DB::table('role_has_permissions')->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
                ->where('role_has_permissions.permission_id', $history)->where('roles.guard_name', 'web')->pluck('roles.id');
            $direct = DB::table('model_has_permissions')->where('permission_id', $history)->get();
            foreach (DB::table('permissions')->whereIn('name', self::NEW)->where('guard_name', 'web')->pluck('id') as $permissionId) {
                foreach ($roles as $roleId) {
                    DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
                foreach ($direct as $grant) {
                    DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'model_type' => $grant->model_type, 'model_id' => $grant->model_id, 'team_id' => $grant->team_id]);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Grants already made are access decisions, not schema; rolling back does not take them away.
    }
};
