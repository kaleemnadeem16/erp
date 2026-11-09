<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Tax\Actions\DeclareQuotationTaxSource;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\TaxDutyManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\AssertsPopulatedRollbackRefusal;
use Tests\TestCase;

class QuotationAccessRollbackProofTest extends TestCase
{
    use AssertsPopulatedRollbackRefusal, RefreshDatabase;

    public function test_every_populated_generation_migration_including_the_timeout_refuses_rollback(): void
    {
        User::factory()->create();
        foreach (['2026_11_01_100000_create_company_access_generations.php', '2026_11_01_110000_fence_company_access_security_writers.php',
            '2026_11_01_120000_fence_quotation_family_writers.php', '2026_11_10_000000_bound_company_access_barrier_waits.php'] as $filename) {
            $this->assertPopulatedRollbackRefused($filename, ['company_access_generations']);
        }
    }

    public function test_populated_own_scope_variant_refuses_rollback(): void
    {
        $user = User::factory()->create();
        DB::table('access_scope_limits')->insert(['company_id' => 1, 'user_id' => $user->id, 'permission' => 'sales.quotation.view', 'scope' => 'OWN', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertPopulatedRollbackRefused('2026_10_30_110000_extend_access_scope_limits_for_quotation_own.php', ['access_scope_limits']);
    }

    public function test_populated_tax_source_migration_refuses_rollback(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $actor = User::factory()->create();
        Permission::findOrCreate(TaxDutyManifest::CONFIGURE, 'web');
        $actor->givePermissionTo(TaxDutyManifest::CONFIGURE);
        $this->app->instance(TaxDutyManifest::class, new TaxDutyManifest([TaxDutyManifest::CONFIGURE => 'ACTIVE']));
        $generation = (int) DB::table('tax_generations')->where('company_id', 1)->value('generation');
        app(DeclareQuotationTaxSource::class)->jurisdiction($actor, 'synthetic', new EffectivePeriod('2026-01-01', '2027-01-01'), 'Synthetic rollback proof', 'rollback-proof', $generation);
        $this->assertPopulatedRollbackRefused('2026_10_31_130000_create_tax_quotation_source_facts.php', ['tax_quotation_jurisdictions']);
    }

    public function test_populated_customer_decision_receipt_refuses_rollback(): void
    {
        $actor = User::factory()->create();
        // A receipt must now name a real case (issue #99), so the fixture creates one. This is a valid synthetic storage fixture,
        // not a customer acceptance or a claim that a business Action has been executed.
        $case = DB::table('customer_decision_cases')->insertGetId(['company_id' => 1, 'party_id' => Party::create(['code' => 'RBPROOF', 'name' => 'Rollback proof'])->id,
            'intake_identity' => 'rollback-proof', 'recorded_by' => $actor->id, 'recorded_at' => now()]);
        DB::table('sales_customer_decision_receipts')->insert(['company_id' => 1, 'actor_id' => $actor->id, 'request_key' => 'rollback-proof',
            'operation' => 'synthetic', 'case_id' => $case, 'payload_hash' => str_repeat('a', 64), 'completed_at' => now(), 'result' => '{}']);
        $this->assertPopulatedRollbackRefused('2026_11_02_110000_create_sales_customer_decision_receipts.php', ['sales_customer_decision_receipts']);
    }
}
