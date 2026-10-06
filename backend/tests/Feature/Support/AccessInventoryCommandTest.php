<?php

namespace Tests\Feature\Support;

use App\Console\Commands\AuditAccessInventory;
use App\Models\Role;
use App\Models\User;
use App\Modules\Estimating\Support\RecipeReadAccess;
use App\Modules\Inventory\Support\NegativeStockReadAccess;
use App\Modules\Inventory\Support\PhysicalCountReadAccess;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class AccessInventoryCommandTest extends TestCase
{
    use DatabaseMigrations;

    public function test_inventory_covers_each_company_roles_direct_grants_and_token_actors_without_mutation(): void
    {
        $member = PermissionedActor::grant(User::factory()->create(), ['currency.exchange-rate.manage', 'inventory.stock.view']);
        $role = Role::create(['team_id' => 1, 'name' => 'Chart maintainer']);
        PermissionedActor::grant(User::factory()->create(), ['accounting.chart-of-account.manage']);
        $role->givePermissionTo('accounting.chart-of-account.manage');
        $member->assignRole($role);
        Role::create(['team_id' => 1, 'name' => 'Unassigned role']);
        $cleartext = $member->createToken('Integration actor')->plainTextToken;
        DB::table('companies')->insert(['id' => 2, 'name' => 'Second company']);
        $foreignId = DB::table('users')->insertGetId(['company_id' => 2, 'name' => 'Foreign service actor', 'email' => 'service@example.com', 'password' => 'secret-hash']);
        Role::create(['team_id' => 2, 'name' => 'Second company role']);
        $permissionId = DB::table('permissions')->where('name', 'inventory.stock.view')->value('id');
        DB::table('model_has_permissions')->insert(['permission_id' => $permissionId, 'model_type' => User::class, 'model_id' => $foreignId, 'team_id' => 2]);
        DB::table('personal_access_tokens')->insert(['tokenable_type' => 'UnknownService', 'tokenable_id' => 123, 'name' => 'Unknown actor', 'token' => hash('sha256', 'unknown-secret'), 'abilities' => '["*"]']);
        $before = $this->snapshot();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->assertSame(0, Artisan::call('erp:audit-access-inventory'));
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(2, $report['companies']);
        $own = collect($report['companies'][0]['members'])->firstWhere('id', $member->id);
        $this->assertSame(['currency.exchange-rate.manage', 'inventory.stock.view'], $own['direct_grants']);
        $this->assertContains('accounting.chart-of-account.manage', $own['effective_capabilities']);
        $this->assertNotContains('accounting.chart-of-account.manage', $own['missing_audit_capabilities']);
        $this->assertContains('inventory.movement.view', $own['missing_audit_capabilities']);
        $this->assertContains('Unassigned role', array_column($report['companies'][0]['roles'], 'name'));
        $this->assertSame('Integration actor', $own['bearer_tokens'][0]['name']);
        $this->assertSame('OWNER_CONFIRMATION_REQUIRED', $own['service_actor_classification']);
        $foreign = $report['companies'][1]['members'][0];
        $this->assertSame(['inventory.stock.view'], $foreign['effective_capabilities']);
        $this->assertSame('UnknownService', $report['unresolved_token_actors'][0]['actor_type']);
        foreach ([$cleartext, 'secret-hash', 'unknown-secret', '"password"', '"token"', '"abilities"'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
        $this->assertContains('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY', $queries);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(AuditAccessInventory::CAPABILITIES, $report['audit_capabilities']);
    }

    public function test_it_reports_who_is_limited_to_their_units_and_which_units_they_belong_to(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $limited = User::factory()->create();
        $role = Role::create(['team_id' => 1, 'name' => 'Warehouse clerk']);
        $limited->assignRole($role);
        $free = User::factory()->create();
        $unit = DB::table('organisation_units')->insertGetId(['company_id' => 1, 'code' => 'khi', 'name' => 'Karachi site', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organisation_unit_members')->insert(['company_id' => 1, 'unit_id' => $unit, 'user_id' => $limited->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('access_scope_limits')->insert(['company_id' => 1, 'user_id' => $limited->id, 'role_id' => $role->id, 'scope' => 'MY_UNITS', 'created_at' => now(), 'updated_at' => now()]);
        $before = $this->snapshot();

        $this->assertSame(0, Artisan::call('erp:audit-access-inventory'));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $members = collect($report['companies'][0]['members']);

        $this->assertSame([['role_id' => $role->id, 'permission' => null, 'scope' => 'MY_UNITS']], $members->firstWhere('id', $limited->id)['scope_limits']);
        $this->assertSame([['id' => $unit, 'name' => 'Karachi site']], $members->firstWhere('id', $limited->id)['organisation_units']);
        $this->assertSame([], $members->firstWhere('id', $free->id)['scope_limits'], 'no limit means company-wide');
        $this->assertSame($before, $this->snapshot(), 'still read-only');
    }

    public function test_it_flags_members_who_lost_a_read_that_used_to_be_open_to_everyone_without_mutating_anything(): void
    {
        $stockViewer = PermissionedActor::grant(User::factory()->create(), ['inventory.stock.view']);
        $formulator = PermissionedActor::grant(User::factory()->create(), ['estimating.formulation.manage']);
        $nobody = User::factory()->create();
        $planner = User::factory()->create();
        $role = Role::create(['team_id' => 1, 'name' => 'Production planner']);
        $role->givePermissionTo(PermissionedActor::grant(User::factory()->create(), ['estimating.recipe.view'])->getAllPermissions());
        $planner->assignRole($role);
        DB::table('companies')->insert(['id' => 2, 'name' => 'Second company']);
        $foreignId = DB::table('users')->insertGetId(['company_id' => 2, 'name' => 'Foreign', 'email' => 'foreign@example.com', 'password' => 'x']);
        $before = $this->snapshot();

        $this->assertSame(0, Artisan::call('erp:audit-access-inventory'));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        // The report is built from the gates' own capability lists, so it cannot drift from what the application enforces.
        $this->assertSame(AuditAccessInventory::READ_GATES, $report['read_gates']);
        $this->assertSame(NegativeStockReadAccess::PERMISSIONS, $report['read_gates']['A8-negative-stock-override-requests']);
        $this->assertSame(PhysicalCountReadAccess::PERMISSIONS, $report['read_gates']['A8-physical-counts']);
        $this->assertSame(RecipeReadAccess::PERMISSIONS, $report['read_gates']['A9-recipes']);

        $lost = fn (User $user): array => collect($report['companies'][0]['members'])->firstWhere('id', $user->id)['read_access_lost'];
        $this->assertSame(['A9-recipes'], $lost($stockViewer), 'stock access keeps override and count reads but not recipes');
        $this->assertSame(['A8-negative-stock-override-requests', 'A8-physical-counts'], $lost($formulator), 'recipe maintenance keeps recipes only');
        $this->assertSame(['A8-negative-stock-override-requests', 'A8-physical-counts', 'A9-recipes'], $lost($nobody));
        $this->assertContains('A8-negative-stock-override-requests', $lost($planner));
        $this->assertNotContains('A9-recipes', $lost($planner), 'access through a role counts');

        $summary = $report['companies'][0]['read_gate_summary'];
        $this->assertContains($nobody->id, $summary['A9-recipes']['members_without_access']);
        $this->assertNotContains($planner->id, $summary['A9-recipes']['members_without_access']);
        $this->assertNotContains($foreignId, $summary['A9-recipes']['members_without_access'], 'another company is reported under its own entry');
        $this->assertContains($foreignId, $report['companies'][1]['read_gate_summary']['A9-recipes']['members_without_access']);
        $this->assertSame($before, $this->snapshot());
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $result = [];
        foreach (['companies', 'users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'personal_access_tokens', 'activity_log'] as $table) {
            $result[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }
}
