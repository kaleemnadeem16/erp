<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/** Company schema setup belongs to existing full access administrators, not ordinary master-data maintainers. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore(['name' => 'administration.custom-field.manage', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        $ids = DB::table('permissions')->where('guard_name', 'web')->pluck('id', 'name');
        $triggers = array_filter([$ids->get('administration.role.manage'), $ids->get('administration.access.assign')]);
        if (count($triggers) !== 2) {
            return;
        }
        $new = $ids->get('administration.custom-field.manage');
        $roles = DB::table('role_has_permissions')->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->whereIn('permission_id', $triggers)->where('roles.guard_name', 'web')
            ->groupBy('role_id')->havingRaw('COUNT(DISTINCT permission_id) = 2')->pluck('role_id');
        foreach ($roles as $role) {
            DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $new]);
        }
        $team = config('permission.column_names.team_foreign_key', 'team_id');
        $direct = DB::table('model_has_permissions')->whereIn('permission_id', $triggers)
            ->select(['model_type', 'model_id', $team])->groupBy(['model_type', 'model_id', $team])
            ->havingRaw('COUNT(DISTINCT permission_id) = 2')->get();
        foreach ($direct as $holder) {
            DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $new, 'model_type' => $holder->model_type, 'model_id' => $holder->model_id, $team => $holder->{$team}]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve manual and rollout grants; ownership cannot be distinguished on rollback.
    }
};
