<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grouped package tracking (ADRs 0090–0093) reached browsers with its `inventory.package-state.*` capabilities registered but granted to nobody. Following the
 * safe-rollout rule (authorization.md R5) this grants, additively and idempotently, only the capabilities that CONTINUE a duty a person already holds, so the
 * day an item is switched to grouped tracking nobody who counted, posted counts or reversed receipts is locked out of doing the same on that item:
 *
 *   view     ← inventory.stock.view, inventory.physical_count.post, purchasing.goods-receipt.reverse   (to see the breakdown they act on)
 *   recount  ← inventory.physical_count.post                                                              (posting a grouped count line)
 *   reverse  ← purchasing.goods-receipt.reverse                                                           (reversing a grouped receipt line)
 *
 * Deliberately NOT granted: `activate` (a one-way change an administrator chooses), and the NEW duties `issue`, `open` and `transfer`, which no one has held
 * before — they are assigned through roles like any new duty (recipe in docs/domain/package-issue-operations.md). A count-only role is never granted `view`:
 * it counts blind. Administrators are covered by `erp:sync-admin-permissions` on deploy.
 */
return new class extends Migration
{
    private const GRANTS = [
        'inventory.package-state.view' => ['inventory.stock.view', 'inventory.physical_count.post', 'purchasing.goods-receipt.reverse'],
        'inventory.package-state.recount' => ['inventory.physical_count.post'],
        'inventory.package-state.reverse' => ['purchasing.goods-receipt.reverse'],
    ];

    public function up(): void
    {
        $now = now();
        $team = config('permission.column_names.team_foreign_key', 'team_id');
        foreach (self::GRANTS as $new => $triggers) {
            DB::table('permissions')->insertOrIgnore(['name' => $new, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
            $newId = DB::table('permissions')->where('name', $new)->where('guard_name', 'web')->value('id');
            $triggerIds = DB::table('permissions')->whereIn('name', $triggers)->where('guard_name', 'web')->pluck('id')->all();
            if ($triggerIds === []) {
                continue;
            }
            foreach (DB::table('role_has_permissions')->whereIn('permission_id', $triggerIds)->distinct()->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $newId, 'role_id' => $roleId]);
            }
            foreach (DB::table('model_has_permissions')->whereIn('permission_id', $triggerIds)->select('model_type', 'model_id', $team)->distinct()->get() as $row) {
                DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $newId, 'model_type' => $row->model_type, 'model_id' => $row->model_id, $team => $row->{$team}]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Grants are additive and indistinguishable from later manual ones, so they are left in place. */
    public function down(): void {}
};
