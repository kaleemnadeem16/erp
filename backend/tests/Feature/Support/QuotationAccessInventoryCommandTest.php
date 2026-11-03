<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Role;
use App\Models\User;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use App\Support\FullAccessMandates;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class QuotationAccessInventoryCommandTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        // Security changes intentionally make rollback refuse; retain the production guard.
        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_inventory_reports_every_conferring_path_without_granting_or_disclosing_credentials(): void
    {
        $member = PermissionedActor::grant(User::factory()->create(), [Sales::QUOTATION_VIEW, Sales::QUOTATION_PREPARE]);
        $role = Role::create(['team_id' => 1, 'name' => 'A name is not authority']);
        $role->givePermissionTo(Sales::QUOTATION_VIEW);
        $member->assignRole($role);
        DB::table('access_scope_limits')->insert(['company_id' => 1, 'user_id' => $member->id, 'role_id' => $role->id, 'scope' => 'OWN', 'created_at' => now(), 'updated_at' => now()]);
        $unused = Role::create(['team_id' => 1, 'name' => 'Unassigned quotation role']);
        $unused->givePermissionTo(Sales::QUOTATION_PREPARE);
        DB::table('companies')->insert(['id' => 2, 'name' => 'Other company']);
        $foreign = DB::table('users')->insertGetId(['company_id' => 2, 'name' => 'Not in this report', 'email' => 'foreign-private@example.test', 'password' => 'foreign-private-hash']);
        DB::table('model_has_permissions')->insert(['permission_id' => DB::table('permissions')->where('name', Sales::QUOTATION_VIEW)->value('id'), 'model_type' => $member->getMorphClass(), 'model_id' => $foreign, 'team_id' => 2]);
        $suspended = User::factory()->create(['status' => 'SUSPENDED']);
        config(['access.full_access_mandates' => [
            ['company_id' => 1, 'role_id' => $role->id, 'mandate' => FullAccessMandates::ALL, 'approval_reference' => 'test-owner-review', 'effective_from' => '2020-01-01'],
            ['company_id' => 2, 'role_id' => 999, 'mandate' => FullAccessMandates::ALL, 'approval_reference' => 'foreign-mandate-private', 'effective_from' => '2020-01-01'],
        ]]);
        $before = $this->snapshot();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->assertSame(0, Artisan::call('sales:audit-quotation-access', ['--company' => '1']));
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['company_id']);
        $this->assertSame('BLOCKED', $report['production_activation']);
        $person = collect($report['members'])->firstWhere('id', $member->id);
        $this->assertEqualsCanonicalizing([
            ['kind' => 'role', 'role_id' => $role->id, 'permission' => Sales::QUOTATION_VIEW, 'scope' => 'OWN'],
            ['kind' => 'direct', 'role_id' => null, 'permission' => Sales::QUOTATION_VIEW, 'scope' => 'ALL'],
            ['kind' => 'direct', 'role_id' => null, 'permission' => Sales::QUOTATION_PREPARE, 'scope' => 'ALL'],
        ], $person['grant_paths']);
        $this->assertCount(2, $person['unrestricted_paths']);
        $this->assertTrue($person['configured_mandate_paths'][0]['generic_mandate_qualifies']);
        $this->assertFalse($person['configured_mandate_paths'][0]['quotation_rollout_approved']);
        $this->assertFalse(collect($report['members'])->firstWhere('id', $suspended->id)['configured_mandate_paths'][0]['generic_mandate_qualifies']);
        $this->assertSame('SUSPENDED', collect($report['members'])->firstWhere('id', $suspended->id)['status']);
        $this->assertContains('Unassigned quotation role', array_column($report['roles'], 'name'));
        $this->assertSame('STAGED', collect($report['shipped_duties'])->firstWhere('name', Sales::QUOTATION_VIEW)['status']);
        foreach (['foreign-private@example.test', 'foreign-private-hash', 'foreign-mandate-private', '"password"', '"token"', '"remember_token"'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
        $this->assertContains('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY', $queries);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_an_explicit_existing_company_is_required(): void
    {
        foreach ([[], ['--company' => '0'], ['--company' => '1 OR 1=1'], ['--company' => '999999']] as $arguments) {
            $this->assertSame(1, Artisan::call('sales:audit-quotation-access', $arguments));
        }
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $result = [];
        foreach (['users', 'roles', 'permissions', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'access_scope_limits', 'company_access_generations', 'activity_log'] as $table) {
            $result[$table] = DB::table($table)->orderByRaw('to_jsonb("'.$table.'")::text')->get()->toJson();
        }

        return $result;
    }
}
