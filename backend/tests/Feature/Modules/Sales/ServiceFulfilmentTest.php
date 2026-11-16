<?php

namespace Tests\Feature\Modules\Sales;

use App\Modules\Jobs\Actions\CompleteJob;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Sales\Actions\PrepareHandoverPlan;
use App\Modules\Sales\Actions\PreviewHandover;
use App\Modules\Sales\Support\Handover\Release\ScopeHeader;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\Jobs\BuildsStartedSalesJob;
use Tests\TestCase;

/**
 * Phase 5 — the Sales-owned fulfilment receipt table itself: immutable, bound by composite keys to the Job's own release, completion event, deliverable and conditions, one per
 * release and per completion, and never committed without (or apart from) its COMPLETE event. Also the migration's backout and apply safety. Disabled: no duty is ACTIVE.
 */
class ServiceFulfilmentTest extends TestCase
{
    use BuildsStartedSalesJob;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStartedJobFixture();
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_12_14_200000_create_service_fulfilment_receipts.php');
    }

    private function completedJob(): ServiceJob
    {
        $job = $this->startedJob();
        app(CompleteJob::class)->execute($this->checker, $job->id, (int) $job->fresh()->generation, 'c1', JobLifecycleIntent::newCommandIdentity(), 'Delivered in full.');
        $this->settleConstraints();

        return $job->refresh();
    }

    /** The database must refuse the write, and for the stated reason (a different, unrelated error would not prove the guard). */
    private function refusedBecause(string $fragment, callable $work): void
    {
        try {
            DB::transaction($work);
            $this->fail("The database must refuse this write ({$fragment}).");
        } catch (QueryException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    public function test_the_receipt_is_immutable_and_the_database_refuses_every_forged_receipt(): void
    {
        $job = $this->completedJob();
        $valid = (array) DB::table('handover_fulfilment_receipts')->where('job_id', $job->id)->first();
        unset($valid['id'], $valid['recorded_at']);

        $this->refusedBecause('append-only', fn () => DB::table('handover_fulfilment_receipts')->where('job_id', $job->id)->update(['applicability_pin' => 'changed']));
        $this->refusedBecause('append-only', fn () => DB::table('handover_fulfilment_receipts')->where('job_id', $job->id)->delete());
        $this->refusedBecause('append-only', fn () => DB::statement('TRUNCATE handover_fulfilment_receipts'));
        $this->refusedBecause('handover_fulfilment_receipts_release_unique', fn () => DB::table('handover_fulfilment_receipts')->insert($valid));

        // With the one genuine receipt removed (guards off, as an attacker with database access could), each forged replacement is refused by the guard itself, for its own reason.
        DB::statement('SET LOCAL session_replication_role = replica');
        DB::table('handover_fulfilment_receipts')->where('job_id', $job->id)->delete();
        DB::statement('SET LOCAL session_replication_role = DEFAULT');

        $releaseEvent = (int) DB::table('job_events')->where('job_id', $job->id)->where('operation', 'RELEASE')->value('id');
        // [reason the database gives, the forged change]. The insert guard speaks first for everything it can prove; the composite keys are the second line.
        $forgeries = [
            ['the digest of the agreed deliverable text', ['deliverable_digest' => ScopeHeader::digest('A different deliverable')]],
            ['exact agreed deliverable and conditions', ['condition_identity' => 'forged-conditions']],
            ['exact agreed deliverable and conditions', ['release_event_id' => 9999999]],
            ['exact agreed deliverable and conditions', ['basis_id' => 9999999]],
            ['COMPLETE event of its Job and the actor', ['completed_by' => $this->worker->id]],
            ['COMPLETE event of its Job and the actor', ['event_id' => $releaseEvent]],
            ['COMPLETE event of its Job and the actor', ['company_id' => 999]],
        ];
        foreach ($forgeries as [$fragment, $change]) {
            $this->refusedBecause($fragment, fn () => DB::table('handover_fulfilment_receipts')->insert($change + $valid));
        }
        // The composite keys hold even where the insert guard itself is switched off: a receipt naming a release that is not the Job's, or another company's parents, has no parent row.
        DB::statement('ALTER TABLE handover_fulfilment_receipts DISABLE TRIGGER handover_fulfilment_receipt_guard');
        try {
            $this->refusedBecause('handover_fulfilment_receipts_origin_fk', fn () => DB::table('handover_fulfilment_receipts')->insert(['release_event_id' => 9999999] + $valid));
            $this->refusedBecause('foreign key', fn () => DB::table('handover_fulfilment_receipts')->insert(['company_id' => 999] + $valid));
        } finally {
            DB::statement('ALTER TABLE handover_fulfilment_receipts ENABLE TRIGGER handover_fulfilment_receipt_guard');
        }
        // The well-formed receipt is accepted, so the refusals above were for their stated reasons and not a malformed fixture.
        DB::table('handover_fulfilment_receipts')->insert($valid);
        $this->settleConstraints();
        $this->assertSame(1, DB::table('handover_fulfilment_receipts')->count());
    }

    public function test_a_complete_event_never_commits_without_its_receipt(): void
    {
        $job = $this->startedJob();
        $head = DB::table('service_jobs')->where('id', $job->id)->first();
        $event = (int) DB::table('job_events')->insertGetId(['company_id' => 1, 'job_id' => $job->id, 'sequence' => (int) DB::table('job_events')->where('job_id', $job->id)->max('sequence') + 1,
            'operation' => 'COMPLETE', 'from_state' => 'ACTIVE', 'to_state' => 'COMPLETED', 'prior_generation' => $head->generation, 'next_generation' => $head->generation + 1,
            'occurrence_precision' => 'NONE', 'recorded_at' => now(), 'actor_id' => $this->checker->id, 'evidence_version' => 1, 'reason' => 'Raw',
            'command_identity' => JobLifecycleIntent::newCommandIdentity(), 'semantic_intent_hash' => str_repeat('a', 64)]);
        DB::table('job_operation_receipts')->insert(['company_id' => 1, 'job_id' => $job->id, 'actor_id' => $this->checker->id, 'request_key' => 'raw-'.$event, 'operation' => 'COMPLETE',
            'intent_hash' => str_repeat('a', 64), 'result_event_id' => $event, 'contract_version' => 1, 'recorded_at' => now()]);
        DB::table('service_jobs')->where('id', $job->id)->update(['state' => 'COMPLETED', 'generation' => $head->generation + 1]);
        $baseline = DB::table('job_baselines')->where('job_id', $job->id)->orderByDesc('version')->first();
        $component = DB::table('job_baseline_components')->where('job_id', $job->id)->first();
        $origin = DB::table('job_sales_origins')->where('job_id', $job->id)->first();
        DB::table('job_completions')->insert(['company_id' => 1, 'job_id' => $job->id, 'event_id' => $event, 'baseline_id' => $baseline->id, 'release_event_id' => $origin->release_event_id,
            'condition_identity' => $component->condition_identity, 'baseline_fingerprint' => $baseline->fingerprint, 'statement' => 'Raw', 'completed_by' => $this->checker->id, 'occurrence_precision' => 'NONE']);

        // Everything else about the completion is well formed; only the Sales receipt is missing.
        $this->refusedBecause('must carry exactly one Sales fulfilment receipt', fn () => DB::statement('SET CONSTRAINTS ALL IMMEDIATE'));
    }

    public function test_a_second_accepted_service_cannot_borrow_the_first_ones_baseline(): void
    {
        $this->releaseTheService();
        $second = $this->serviceAcceptance($this->checker, $this->customer->id, 'SERVICE_ITEM', '-b');
        $token = app(PreviewHandover::class)($this->checker, $second['acceptance'], $second['inputs'])['preview_token'];
        app(PrepareHandoverPlan::class)($this->checker, 'prepare-b', $second['acceptance'], $second['inputs'], $token);
        $stages = DB::table('handover_stages')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertCount(2, $stages, 'two real accepted, prepared stages');
        [$first, $other] = $stages;
        $release = (array) DB::table('handover_release_events')->where('stage_id', $first)->first();
        unset($release['id']);
        // A release of the SECOND stage that names the FIRST stage's baseline (same company, real rows, wrong parent).
        $forged = ['stage_id' => $other, 'request_key' => 'forged-baseline'] + $release;
        $this->refusedBecause('must bind its own stage baseline', fn () => DB::table('handover_release_events')->insert($forged));
        $this->assertSame(1, DB::table('handover_release_events')->count());
    }

    public function test_an_empty_store_backs_out_and_applies_again_and_a_populated_one_refuses(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasTable('handover_fulfilment_receipts'));
        $this->assertSame(0, (int) DB::selectOne("select count(*) as n from pg_proc where proname in ('handover_fulfilment_receipt_guard','handover_fulfilment_consistency_v1')")->n);
        foreach (['service_jobs', 'job_events', 'job_completions', 'handover_release_events'] as $kept) {
            $this->assertTrue(Schema::hasTable($kept), "{$kept} is untouched by the backout");
        }
        $migration->up();
        $this->assertTrue(Schema::hasTable('handover_fulfilment_receipts'));
        $guards = DB::select("select tgname from pg_trigger where not tgisinternal and tgrelid = 'handover_fulfilment_receipts'::regclass");
        $this->assertGreaterThanOrEqual(4, count($guards), 'row guard, truncate guard, insert guard and the commit-time check');

        $this->completedJob();
        try {
            DB::transaction(fn () => $this->migration()->down());
            $this->fail('A populated store must refuse backout.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('erase Sales fulfilment evidence', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('handover_fulfilment_receipts'), 'the refused backout dropped nothing');
        $this->assertSame(1, DB::table('handover_fulfilment_receipts')->count());
    }

    public function test_applying_refuses_when_a_sales_origin_job_was_already_completed_without_a_receipt(): void
    {
        $job = $this->completedJob();
        DB::statement('SET LOCAL session_replication_role = replica');
        DB::table('handover_fulfilment_receipts')->where('job_id', $job->id)->delete();
        DB::statement('SET LOCAL session_replication_role = DEFAULT');
        $this->migration()->down();
        try {
            $this->migration()->up();
            $this->fail('A genuine fulfilment record cannot be back-filled.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be back-filled', $e->getMessage());
        }
        $this->assertFalse(Schema::hasTable('handover_fulfilment_receipts'));
    }
}
