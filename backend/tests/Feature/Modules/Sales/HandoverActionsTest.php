<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Modules\Sales\Actions\PrepareHandoverPlan;
use App\Modules\Sales\Actions\PreviewHandover;
use App\Modules\Sales\Models\HandoverPlan;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsHandoverFixture;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

class HandoverActionsTest extends TestCase
{
    use BuildsHandoverFixture;
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        $this->activateHandover();
        foreach ($this->handoverDuties() as $duty) {
            Permission::findOrCreate($duty, 'web');
            $this->checker->givePermissionTo($duty);
        }
        $this->f = $this->serviceAcceptance($this->checker, $this->customer->id);
    }

    private function preview(array $inputs = []): array
    {
        return app(PreviewHandover::class)($this->checker, $this->f['acceptance'], $inputs ?: $this->f['inputs']);
    }

    private function prepare(string $key, string $token, ?array $inputs = null): array
    {
        return app(PrepareHandoverPlan::class)($this->checker, $key, $this->f['acceptance'], $inputs ?? $this->f['inputs'], $token);
    }

    public function test_preview_then_prepare_captures_a_pending_full_plan_and_both_retry_identities_replay(): void
    {
        $p = $this->preview();
        $this->assertTrue($p['readiness']['preparation_ready']);
        $this->assertSame('NOT_READY', $p['readiness']['status']);
        $this->assertContains('SERVICE_COST_CERTAINTY_UNKNOWN', $p['readiness']['missing']);
        $this->assertNull($p['operational']['quantity']);
        $this->assertDatabaseCount('handover_plans', 0);
        $out = $this->prepare('prepare', $p['preview_token']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        foreach (['handover_plans', 'handover_allocations', 'handover_stages', 'handover_baselines', 'handover_readiness_evidence'] as $table) {
            $this->assertDatabaseCount($table, 1);
            foreach ([fn () => DB::table($table)->delete(), fn () => DB::table($table)->update(['company_id' => 1]), fn () => DB::statement("TRUNCATE {$table} CASCADE")] as $write) {
                try {
                    DB::transaction($write);
                    $this->fail('Preparation history is append-only.');
                } catch (QueryException $e) {
                    $this->assertSame('23514', $e->errorInfo[0]);
                }
            }
        }
        $this->assertSame($out['plan_id'], $this->prepare('prepare', 'expired')['plan_id']);
        $this->assertSame($out['plan_id'], $this->prepare('fresh', 'expired')['plan_id']);
        $this->assertDatabaseCount('service_jobs', 0);
        $this->assertDatabaseCount('customer_acceptances', 1);
    }

    public function test_changed_contact_refuses_old_review_and_writes_no_receipt_or_plan(): void
    {
        $p = $this->preview();
        DB::table('party_contacts')->where('id', $this->f['contact'])->update(['deleted_at' => now()]);
        try {
            $this->prepare('stale', $p['preview_token']);
            $this->fail('Stale operational review must refuse.');
        } catch (DomainInvariantException $e) {
            $this->assertSame('PREVIEW_TOKEN_STALE', $e->reasonCode);
        }
        $this->assertDatabaseCount('handover_plans', 0);
        $this->assertDatabaseMissing('sales_customer_decision_receipts', ['request_key' => 'stale']);
    }

    public function test_other_full_plan_intent_is_refused_and_request_key_reuse_is_distinct(): void
    {
        $p = $this->preview();
        $this->prepare('one', $p['preview_token']);
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('already has a different full-scope');
        $this->prepare('two', $p['preview_token'], array_replace($this->f['inputs'], ['instructions' => 'Another instruction']));
    }

    public function test_staged_duty_denies_even_a_permission_holder(): void
    {
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->expectException(AuthorizationException::class);
        $this->preview();
    }

    public function test_suspended_actor_cannot_replay_and_plan_is_not_changed(): void
    {
        $this->prepare('one', $this->preview()['preview_token']);
        DB::table('users')->where('id', $this->checker->id)->update(['status' => 'SUSPENDED']);
        $this->assertSame(1, HandoverPlan::count());
        $this->expectException(AuthorizationException::class);
        $this->prepare('one', 'expired');
    }

    public function test_a_failure_after_the_baseline_rolls_back_the_whole_plan_and_receipt(): void
    {
        $p = $this->preview();
        DB::unprepared("CREATE OR REPLACE FUNCTION test_handover_readiness_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Synthetic final evidence failure' USING ERRCODE='23514'; END \$\$; CREATE TRIGGER test_handover_readiness_failure BEFORE INSERT ON handover_readiness_evidence FOR EACH ROW EXECUTE FUNCTION test_handover_readiness_failure()");
        try {
            $this->prepare('forced-failure', $p['preview_token']);
            $this->fail('A final evidence failure must abort all earlier inserts.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        foreach (['handover_plans', 'handover_allocations', 'handover_stages', 'handover_baselines', 'handover_readiness_evidence'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseMissing('sales_customer_decision_receipts', ['request_key' => 'forced-failure']);
    }

    public function test_populated_migration_refuses_backout_with_guards_intact(): void
    {
        $this->prepare('prepare', $this->preview()['preview_token']);
        $migration = require database_path('migrations/2026_12_12_100000_create_service_handover_preparation.php');
        try {
            DB::transaction(fn () => $migration->down());
            $this->fail('Preparation evidence cannot be discarded.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('separately approved backout', $e->getMessage());
        }
        $this->assertDatabaseCount('handover_plans', 1);
    }

    public function test_database_rejects_incomplete_readiness_and_extra_commercial_fields_in_operational_snapshot(): void
    {
        $this->prepare('prepare', $this->preview()['preview_token']);
        $baseline = DB::table('handover_baselines')->first();
        $snapshot = json_decode($baseline->operational_snapshot, true, 32, JSON_THROW_ON_ERROR);
        $writes = [
            fn () => DB::table('handover_readiness_evidence')->insert(['company_id' => 1, 'stage_id' => $baseline->stage_id, 'assessment' => '{}']),
            fn () => DB::table('handover_baselines')->insert(['company_id' => 1, 'stage_id' => $baseline->stage_id, 'issued_line_id' => $baseline->issued_line_id, 'version' => 1, 'operational_snapshot' => json_encode($snapshot + ['unit_price' => '1.0000'], JSON_THROW_ON_ERROR)]),
        ];
        foreach ($writes as $write) {
            try {
                DB::transaction($write);
                $this->fail('Invalid evidence must fail its CHECK, before uniqueness checks.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
    }

    public function test_expired_or_changed_input_review_is_refused(): void
    {
        $p = $this->preview();
        $this->travel(11)->minutes();
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('Review this acceptance again');
        $this->prepare('expired', $p['preview_token']);
    }
}
