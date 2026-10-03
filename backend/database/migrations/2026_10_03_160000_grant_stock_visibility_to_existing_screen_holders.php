<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR 0083 splits stock reads into availability / stock / movement capabilities and starts enforcing them on the API. The Inventory
 * screens were already shown only to people holding an inventory capability, so to keep every legitimate user working this grants
 * the three new capabilities to every role and every direct assignment that holds one of those inventory capabilities — before the
 * API checks bite, in the same release.
 *
 * One deliberate exception: `inventory.physical_count.count` alone does not trigger a grant. A counter who holds only that capability
 * counts blind (the book quantity is withheld), and an item-level on-hand figure would hand the answer back. A person with that
 * capability *and* any other trigger is still granted, through the other one.
 *
 * Idempotent, and it only ever adds grants. Nothing here creates a role or assigns anything to a person who held none of the triggers.
 */
return new class extends Migration
{
    private const NEW = ['inventory.availability.view', 'inventory.stock.view', 'inventory.movement.view'];

    /** The capabilities that already showed a person the stock overview and ledger screens (screen-access.ts), less the count-only one. */
    private const TRIGGERS = [
        'inventory.item.manage', 'inventory.category.manage',
        'inventory.physical_count.create', 'inventory.physical_count.post',
        'inventory.cutover.create', 'inventory.cutover.stage', 'inventory.cutover.validate', 'inventory.cutover.approve',
        'inventory.cutover.commit', 'inventory.cutover.finalise',
        'inventory.cutover.negative_opening.request', 'inventory.cutover.negative_opening.approve',
        'inventory.negative-stock.request', 'inventory.negative-stock.override', 'inventory.negative-stock.approve', 'inventory.negative-stock.review',
        'inventory.recalculation.request', 'inventory.recalculation.approve', 'inventory.recalculation.approve-high-risk',
        'inventory.recalculation.execute', 'inventory.recalculation.review-regeneration',
        'inventory.valuation.view',
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::NEW as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }
        $newIds = DB::table('permissions')->whereIn('name', self::NEW)->where('guard_name', 'web')->pluck('id')->all();
        $triggerIds = DB::table('permissions')->whereIn('name', self::TRIGGERS)->where('guard_name', 'web')->pluck('id')->all();

        if ($triggerIds !== []) {
            foreach (DB::table('role_has_permissions')->whereIn('permission_id', $triggerIds)->distinct()->pluck('role_id') as $roleId) {
                foreach ($newIds as $permissionId) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
                }
            }

            $team = config('permission.column_names.team_foreign_key', 'team_id');
            $direct = DB::table('model_has_permissions')->whereIn('permission_id', $triggerIds)->select('model_type', 'model_id', $team)->distinct()->get();
            foreach ($direct as $row) {
                foreach ($newIds as $permissionId) {
                    DB::table('model_has_permissions')->insertOrIgnore([
                        'permission_id' => $permissionId, 'model_type' => $row->model_type, 'model_id' => $row->model_id, $team => $row->{$team},
                    ]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', self::NEW)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
