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

    public function test_expired_or_changed_input_review_is_refused(): void
    {
        $p = $this->preview();
        $this->travel(11)->minutes();
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('Review this acceptance again');
        $this->prepare('expired', $p['preview_token']);
    }
}
