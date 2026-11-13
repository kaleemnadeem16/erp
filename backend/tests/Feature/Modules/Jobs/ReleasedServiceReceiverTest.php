<?php

namespace Tests\Feature\Modules\Jobs;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Jobs\Actions\AssignJobResponsibility;
use App\Modules\Jobs\Actions\ReceiveReleasedService;
use App\Modules\Jobs\Actions\StartJob;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Authority\JobDuty;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Authority\JobDutyStatus;
use App\Modules\Jobs\Support\Execution\JobExecutionContext;
use App\Modules\Jobs\Support\Execution\JobExecutionContextVerifier;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Jobs\Support\Release\SalesJobOrigins;
use App\Modules\Sales\Actions\PrepareHandoverPlan;
use App\Modules\Sales\Actions\PreviewHandover;
use App\Modules\Sales\Actions\PreviewServiceRelease;
use App\Modules\Sales\Actions\RecordServiceCompletionBasis;
use App\Modules\Sales\Actions\ReleaseHandoverComponent;
use App\Modules\Sales\Support\Handover\Release\JobSalesOrigin;
use App\Modules\Sales\Support\Handover\Release\ReleasedServiceReceiver;
use App\Modules\Sales\Support\Handover\Release\ReleasedServiceReference;
use App\Modules\Sales\Support\Handover\Release\SalesJobExecutionContextVerifier;
use App\Modules\Sales\Support\Handover\Release\ScopeHeader;
use App\Modules\Sales\Support\Handover\Release\VerifiedServiceRelease;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Support\PermissionRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Sales\Concerns\BuildsHandoverFixture;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\Support\Sales\FakeReleasedServiceReceiver;
use Tests\Support\Sales\ScopedReleaseFixtures;
use Tests\TestCase;

/**
 * Phase 5 Batch 1 — the integration proof: a genuine issued → accepted → prepared service is released through the REAL Sales coordinator into the REAL Jobs receiver, in one
 * transaction. Both succeed or both roll back; retries, fresh keys and a second actor create no duplicate Job, number or claim; the database refuses forged, cross-company and
 * same-parent lineage, immutability is enforced, and a released Job really passes through the Sales source verifier. Everything stays disabled: no duty is ACTIVE in production.
 */
class ReleasedServiceReceiverTest extends TestCase
{
    use BuildsHandoverFixture;
    use BuildsIssuableQuotations;
    use RefreshDatabase;
    use ScopedReleaseFixtures;

    /** @var array<string,int> */
    private array $agreementEvidence = [];

    private const JOB_DUTIES = ['job.receive', 'job.assign', 'job.start'];

    private array $f;

    private int $evidenceId;

    private int $stageId;

    private User $worker;

    protected function handoverDuties(): array
    {
        return [P::QUOTATION_PREPARE, P::QUOTATION_VIEW, P::QUOTATION_ISSUE, P::QUOTATION_WITHDRAW, P::CUSTOMER_DECISION_ACCEPT, P::CUSTOMER_DECISION_VIEW, P::HANDOVER_PREPARE,
            P::HANDOVER_COMPLETION_BASIS_RECORD, P::HANDOVER_RELEASE];
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $this->registerSourceFamilies();
        $this->setUpIssuing();
        $this->activateHandover();
        foreach ($this->handoverDuties() as $duty) {
            Permission::findOrCreate($duty, 'web');
            $this->checker->givePermissionTo($duty);
        }
        $this->activateJobs(self::JOB_DUTIES);
        $this->grantJobs($this->checker, ...self::JOB_DUTIES);
        $this->worker = User::factory()->create();
        $this->f = $this->serviceAcceptance($this->checker, $this->customer->id);
        $this->evidenceId = (int) DB::table('customer_decision_evidence')->where('case_id', $this->f['case'])->value('id');
        $token = app(PreviewHandover::class)($this->checker, $this->f['acceptance'], $this->f['inputs'])['preview_token'];
        app(PrepareHandoverPlan::class)($this->checker, 'prepare', $this->f['acceptance'], $this->f['inputs'], $token);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        // The deferred COMMIT-time checks (Jobs lifecycle and receiver completeness) must run at the end of each release, as in production, not row by row.
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->stageId = (int) DB::table('handover_stages')->value('id');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param list<string> $names */
    private function activateJobs(array $names): void
    {
        $this->app->instance(JobDutyManifest::class, new JobDutyManifest(array_map(fn (string $n): JobDuty => new JobDuty($n, JobDutyStatus::Active, 'op', 'projection', 'gate'), $names)));
    }

    private function grantJobs(User $user, string ...$duties): void
    {
        foreach ($duties as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $user->givePermissionTo($duties);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** A basis on its own customer-agreement evidence excluding materials and subcontractors (once per text, so the same text replays). */
    private function recordBasis(string $key = 'basis', string $text = self::AGREED): array
    {
        $full = ScopeHeader::compose('EXCLUDED', 'EXCLUDED', $text);
        $evidence = $this->agreementEvidence[$full] ??= $this->appendAgreementEvidence($this->f['case'], $this->checker->id, $full);

        return app(RecordServiceCompletionBasis::class)($this->checker, $key, $this->f['acceptance'], $full, $evidence);
    }

    private function release(string $key = 'release', ?User $actor = null): array
    {
        $p = app(PreviewServiceRelease::class)($this->checker, $this->stageId);

        return app(ReleaseHandoverComponent::class)($actor ?? $this->checker, $key, $this->stageId, (int) $p['basis_id'], $p['fingerprint']);
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        $out = [];
        foreach (['handover_release_events', 'service_jobs', 'job_events', 'job_baselines', 'job_handover_origins', 'job_sales_origins', 'job_baseline_components', 'job_activities', 'numbering_allocations'] as $t) {
            $out[$t] = DB::table($t)->count();
        }

        return $out;
    }

    private function settle(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function refused(callable $work): void
    {
        try {
            DB::transaction($work);
            $this->fail('The database must refuse this write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    // ---- a genuine release ------------------------------------------------------------------------------------------------

    public function test_a_genuine_release_creates_exactly_one_numbered_job_with_its_whole_evidence_and_consumes_no_claim(): void
    {
        $this->recordBasis();
        $claims = DB::table('customer_scope_claim_heads')->orderBy('id')->get()->all();
        $out = $this->release();
        $this->settle();

        $this->assertFalse($out['replayed']);
        $job = ServiceJob::query()->findOrFail($out['job_id']);
        $this->assertSame($out['job_code'], $job->code);
        $this->assertMatchesRegularExpression('/^JOB-\d{5}$/', $job->code);
        $this->assertSame(['RELEASED', 1], [$job->state, $job->generation]);
        $event = DB::table('job_events')->where('job_id', $job->id)->get();
        $this->assertCount(1, $event);
        $this->assertSame(['RELEASE', 1, null, 'RELEASED', 0, 1, $this->checker->id], [$event[0]->operation, $event[0]->sequence, $event[0]->from_state, $event[0]->to_state, $event[0]->prior_generation, $event[0]->next_generation, $event[0]->actor_id]);
        $this->assertNull($event[0]->command_identity);

        $release = DB::table('handover_release_events')->first();
        $origin = DB::table('job_handover_origins')->where('job_id', $job->id)->first();
        $this->assertSame(['sales-handover-release-v1', 'SERVICE_JOB_RECEIPT', 'stage:'.$this->stageId, $release->intent_hash], [$origin->source_contract_version, $origin->target_purpose, $origin->business_identity, $origin->intent_hash]);
        $this->assertSame($release->id, (int) DB::table('job_sales_origins')->where('job_id', $job->id)->value('release_event_id'));

        $baseline = DB::table('job_baselines')->where('job_id', $job->id)->get();
        $this->assertCount(1, $baseline);
        $this->assertSame(1, $baseline[0]->version);
        $this->assertNull($baseline[0]->predecessor_id);
        $this->assertSame('SIMPLE_ATTESTATION_V1', $baseline[0]->completion_rule_version);

        $component = DB::table('job_baseline_components')->where('job_id', $job->id)->first();
        $this->assertSame($baseline[0]->id, $component->baseline_id);
        $this->assertSame($baseline[0]->scope_description, $component->deliverable_text, 'the baseline scope is the verified deliverable text');
        $this->assertSame(ScopeHeader::compose('EXCLUDED', 'EXCLUDED', self::AGREED), $component->agreed_conditions);
        $this->assertSame($release->condition_identity, $component->condition_identity);
        $this->assertSame($release->basis_id, $component->basis_id);
        $line = DB::table('handover_baselines')->where('id', $release->baseline_id)->first();
        $this->assertSame($line->issued_line_id, $component->issued_line_id);
        $this->assertSame((int) DB::table('handover_stages')->where('id', $this->stageId)->value('allocation_id'), $component->allocation_id);

        $activity = DB::table('job_activities')->where('job_id', $job->id)->get();
        $this->assertCount(1, $activity);
        $this->assertSame('DEFAULT', $activity[0]->kind);

        $this->assertEquals($claims, DB::table('customer_scope_claim_heads')->orderBy('id')->get()->all(), 'a release consumes no acceptance claim');
        $reference = app(ReleasedServiceReceiver::class)->referenceFor($release->id);
        $this->assertSame([$job->id, $job->code], [$reference->jobId, $reference->jobCode]);
        $this->assertNull(app(ReleasedServiceReceiver::class)->referenceFor(999999));
        $this->assertSame('PREPARED', DB::table('handover_stages')->value('state'), 'preparation evidence is never updated');
    }

    public function test_the_same_key_a_fresh_key_and_a_second_actor_create_no_second_job_number_or_claim(): void
    {
        $this->recordBasis();
        $first = $this->release('k1');
        $after = $this->counts();
        $this->assertSame(1, $after['service_jobs']);

        $same = $this->release('k1');
        $fresh = $this->release('k2');
        $second = User::factory()->create();
        $second->givePermissionTo($this->handoverDuties());
        $this->grantJobs($second, 'job.receive');
        $other = $this->release('k3', $second);

        foreach ([$same, $fresh, $other] as $again) {
            $this->assertSame([$first['job_id'], $first['job_code'], $first['release_event_id']], [$again['job_id'], $again['job_code'], $again['release_event_id']]);
        }
        $this->assertTrue($same['replayed']);
        $this->assertSame($after, $this->counts(), 'no Job, number, event or evidence row was added');
    }

    public function test_a_second_receipt_of_the_same_verified_release_is_refused_and_writes_nothing(): void
    {
        $captured = null;
        $real = app(ReceiveReleasedService::class);
        $this->app->instance(ReleasedServiceReceiver::class, new class($real, $captured) implements ReleasedServiceReceiver
        {
            public function __construct(private readonly ReceiveReleasedService $real, public ?VerifiedServiceRelease &$captured) {}

            public function receive(VerifiedServiceRelease $release): ReleasedServiceReference
            {
                $this->captured = $release;

                return $this->real->receive($release);
            }

            public function assertMayReceive(\App\Models\User $actor): void
            {
                $this->real->assertMayReceive($actor);
            }

            public function referenceFor(int $releaseEventId): ?ReleasedServiceReference
            {
                return $this->real->referenceFor($releaseEventId);
            }
        });
        $this->recordBasis();
        $this->release();
        $before = $this->counts();
        $this->assertNotNull($captured);
        $this->expectException(LogicException::class);
        try {
            $real->receive($captured);
        } finally {
            $this->assertSame($before, $this->counts());
        }
    }

    // ---- authority --------------------------------------------------------------------------------------------------------

    public function test_job_receive_is_required_independently_and_a_staged_or_specified_duty_refuses_everyone(): void
    {
        $this->recordBasis();
        $before = $this->counts();
        // (1) holder of every Sales duty but not of job.receive
        $this->checker->revokePermissionTo('job.receive');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertRefusedAuthority(fn () => $this->release('a'));
        // (2) holds job.receive but the duty is SPECIFIED, as shipped
        $this->checker->givePermissionTo('job.receive');
        $this->app->instance(JobDutyManifest::class, JobDutyManifest::shipped());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertRefusedAuthority(fn () => $this->release('b'));
        // (3) an active duty but a suspended actor
        $this->activateJobs(self::JOB_DUTIES);
        $this->checker->forceFill(['status' => 'SUSPENDED'])->save();
        $this->assertRefusedAuthority(fn () => $this->release('c'));
        $this->assertSame($before, $this->counts(), 'every refusal rolled the whole release back, number included');
    }

    private function assertRefusedAuthority(callable $work): void
    {
        try {
            $work();
            $this->fail('Expected an authorisation refusal.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    // ---- atomicity: failure after every write ---------------------------------------------------------------------------

    public function test_a_failure_after_each_write_rolls_the_whole_release_and_the_job_back(): void
    {
        $this->recordBasis();
        $before = $this->counts();
        DB::unprepared("CREATE FUNCTION zz_inject_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'injected failure on %', TG_TABLE_NAME USING ERRCODE='P0001'; END \$\$");
        foreach (['service_jobs', 'job_events', 'job_baselines', 'job_handover_origins', 'job_sales_origins', 'job_baseline_components', 'job_activities', 'numbering_allocations'] as $table) {
            DB::unprepared("CREATE TRIGGER zz_inject AFTER INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION zz_inject_failure()");
            try {
                $this->release('inject-'.$table);
                $this->fail("Expected the injected failure after the {$table} write.");
            } catch (QueryException $e) {
                $this->assertStringContainsString("injected failure on {$table}", $e->getMessage());
            }
            DB::unprepared("DROP TRIGGER zz_inject ON {$table}");
            $this->assertSame($before, $this->counts(), "nothing survived a failure after the {$table} write");
        }
        DB::unprepared('DROP FUNCTION zz_inject_failure()');

        $out = $this->release('clean');
        $this->settle();
        $this->assertSame(1, DB::table('service_jobs')->count(), 'a clean retry creates exactly one Job after all the failed attempts');
        $this->assertSame($out['job_id'], (int) DB::table('service_jobs')->value('id'));
    }

    public function test_a_failure_in_the_receiver_after_the_sales_event_rolls_the_release_event_back(): void
    {
        $this->recordBasis();
        $before = $this->counts();
        $this->app->instance(ReleasedServiceReceiver::class, new class(app(ReceiveReleasedService::class)) implements ReleasedServiceReceiver
        {
            public function __construct(private readonly ReceiveReleasedService $real) {}

            public function receive(VerifiedServiceRelease $release): ReleasedServiceReference
            {
                $this->real->receive($release);
                throw new RuntimeException('Injected failure after the Job was fully written.');
            }

            public function assertMayReceive(\App\Models\User $actor): void
            {
                $this->real->assertMayReceive($actor);
            }

            public function referenceFor(int $releaseEventId): ?ReleasedServiceReference
            {
                return $this->real->referenceFor($releaseEventId);
            }
        });
        $this->expectException(RuntimeException::class);
        try {
            $this->release('x');
        } finally {
            $this->assertSame($before, $this->counts());
        }
    }

    // ---- the database refuses forged, cross-company and same-parent lineage ---------------------------------------------

    /** A released Sales Job, then the same release facts as raw SQL building blocks. */
    private function released(): array
    {
        $this->recordBasis();
        $out = $this->release();
        $this->settle();

        return [$out, (array) DB::table('job_sales_origins')->where('job_id', $out['job_id'])->first(), (array) DB::table('job_baseline_components')->where('job_id', $out['job_id'])->first()];
    }

    public function test_the_database_refuses_a_second_job_for_a_release_cross_company_forged_and_same_parent_attacks(): void
    {
        [$out, $origin, $component] = $this->released();
        unset($origin['id'], $component['id']);
        $other = (int) DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $job2 = (int) DB::table('service_jobs')->insertGetId(['company_id' => 1, 'code' => 'JOB-RAW', 'state' => 'RELEASED', 'generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('job_events')->insert(['company_id' => 1, 'job_id' => $job2, 'sequence' => 1, 'operation' => 'RELEASE', 'from_state' => null, 'to_state' => 'RELEASED', 'prior_generation' => 0, 'next_generation' => 1,
            'occurrence_precision' => 'EXACT', 'recorded_at' => now(), 'actor_id' => $this->checker->id, 'evidence_version' => 1]);
        $release = DB::table('handover_release_events')->first();
        $genericOrigin = [...(array) DB::table('job_handover_origins')->where('job_id', $out['job_id'])->first()];
        unset($genericOrigin['id']);
        DB::table('job_handover_origins')->insert([...$genericOrigin, 'job_id' => $job2, 'target_purpose' => 'SERVICE_JOB_RECEIPT', 'business_identity' => 'stage:999', 'recorded_at' => now()]);

        // one Job per release event: a second Job cannot claim the same release (and its origin identity is not that release's)
        $this->refused(fn () => DB::table('job_sales_origins')->insert([...$origin, 'job_id' => $job2]));
        // cross-company raw rows
        $this->refused(fn () => DB::table('job_sales_origins')->insert([...$origin, 'company_id' => $other]));
        $this->refused(fn () => DB::table('job_baseline_components')->insert([...$component, 'company_id' => $other]));
        $this->refused(fn () => DB::table('job_activities')->insert(['company_id' => $other, 'job_id' => $job2, 'kind' => 'DEFAULT', 'description' => 'x']));
        // a second default activity for the same Job; an activity for a non-default kind
        $this->refused(fn () => DB::table('job_activities')->insert(['company_id' => 1, 'job_id' => $out['job_id'], 'kind' => 'DEFAULT', 'description' => 'again']));
        $this->refused(fn () => DB::table('job_activities')->insert(['company_id' => 1, 'job_id' => $job2, 'kind' => 'EXTRA', 'description' => 'x']));
        // a second component for the baseline, and a component whose facts differ from its release event (same parent, forged leaf)
        $this->refused(fn () => DB::table('job_baseline_components')->insert([...$component]));
        foreach ([['deliverable_text', 'Forged deliverable'], ['agreed_conditions', 'Forged conditions'], ['condition_identity', 'completion-basis:1:1:v9'], ['deliverable_identity', 'issued-scope:0'],
            ['issued_line_id', $component['issued_line_id'] + 1000], ['allocation_id', $component['allocation_id'] + 1000], ['basis_id', $component['basis_id'] + 1000]] as [$col, $value]) {
            $this->refused(fn () => DB::table('job_baseline_components')->insert([...$component, 'baseline_id' => $component['baseline_id'], $col => $value]));
        }
        // a component pointing at a baseline of another Job (same company, wrong parent)
        $baseline2 = (int) DB::table('job_baselines')->insertGetId(['company_id' => 1, 'job_id' => $job2, 'version' => 1, 'fingerprint' => 'fp', 'scope_description' => 's', 'completion_rule_version' => 'c', 'recorded_at' => now()]);
        $this->refused(fn () => DB::table('job_baseline_components')->insert([...$component, 'baseline_id' => $baseline2]));
        $this->assertNotNull($release);
    }

    public function test_every_receiver_table_is_append_only_and_truncate_guarded_and_commit_completeness_is_enforced(): void
    {
        [$out] = $this->released();
        foreach (['job_sales_origins', 'job_baseline_components', 'job_activities'] as $table) {
            $this->refused(fn () => DB::table($table)->update(['company_id' => 1]));
            $this->refused(fn () => DB::table($table)->delete());
        }
        $this->refused(fn () => DB::statement('TRUNCATE job_sales_origins, job_baseline_components'));
        $this->refused(fn () => DB::statement('TRUNCATE job_activities'));
        $this->refused(fn () => DB::statement('TRUNCATE service_jobs CASCADE'));

    }

    /** @return array{0:int,1:array<string,mixed>} a Sales Job's raw rows for a release event that no Job has claimed yet */
    private function rawJobFor(object $event, bool $complete): array
    {
        $now = now();
        $job = (int) DB::table('service_jobs')->insertGetId(['company_id' => 1, 'code' => 'JOB-RAW-'.($complete ? 'OK' : 'BAD'), 'state' => 'RELEASED', 'generation' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('job_events')->insert(['company_id' => 1, 'job_id' => $job, 'sequence' => 1, 'operation' => 'RELEASE', 'from_state' => null, 'to_state' => 'RELEASED', 'prior_generation' => 0, 'next_generation' => 1,
            'occurrence_precision' => 'EXACT', 'recorded_at' => $now, 'actor_id' => $this->checker->id, 'evidence_version' => 1]);
        $baseline = (int) DB::table('job_baselines')->insertGetId(['company_id' => 1, 'job_id' => $job, 'version' => 1, 'fingerprint' => 'fp', 'scope_description' => 'x', 'completion_rule_version' => 'c', 'recorded_at' => $now]);
        DB::table('job_handover_origins')->insert(['company_id' => 1, 'job_id' => $job, 'source_contract_version' => 'sales-handover-release-v1', 'target_purpose' => $event->purpose,
            'business_identity' => 'stage:'.$event->stage_id, 'intent_hash' => $event->intent_hash, 'recorded_at' => $now]);
        DB::table('job_sales_origins')->insert(['company_id' => 1, 'job_id' => $job, 'release_event_id' => $event->id]);
        if ($complete) {
            $hb = DB::table('handover_baselines')->where('id', $event->baseline_id)->first();
            $basis = DB::table('service_completion_bases')->where('id', $event->basis_id)->first();
            DB::table('job_baseline_components')->insert(['company_id' => 1, 'job_id' => $job, 'baseline_id' => $baseline, 'release_event_id' => $event->id,
                'allocation_id' => DB::table('handover_stages')->where('id', $event->stage_id)->value('allocation_id'), 'issued_line_id' => $hb->issued_line_id, 'basis_id' => $basis->id,
                'deliverable_identity' => json_decode($hb->operational_snapshot, true)['deliverable_identity'], 'deliverable_text' => DB::table('quotation_issued_lines')->where('id', $hb->issued_line_id)->value('description'),
                'agreed_conditions' => $basis->agreed_text, 'condition_identity' => $event->condition_identity]);
            DB::table('job_activities')->insert(['company_id' => 1, 'job_id' => $job, 'kind' => 'DEFAULT', 'description' => 'x']);
        }

        return [$job, []];
    }

    public function test_a_sales_job_cannot_commit_without_its_component_and_default_activity_but_a_complete_one_can(): void
    {
        $this->recordBasis();
        $this->app->instance(ReleasedServiceReceiver::class, new FakeReleasedServiceReceiver);
        $this->release();
        $event = DB::table('handover_release_events')->first();
        $this->refused(function () use ($event): void {
            $this->rawJobFor($event, false);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        $this->assertSame(0, DB::table('job_sales_origins')->count());
        [$job] = $this->rawJobFor($event, true);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->assertSame($job, (int) DB::table('job_sales_origins')->value('job_id'), 'the complete raw Job is accepted, so the refusal above was the completeness guard');
    }

    // ---- a released Job passes through the Sales verifier -------------------------------------------------------------------

    public function test_a_really_released_job_is_assigned_and_started_through_the_sales_verifier_and_an_unsupported_origin_refuses(): void
    {
        [$out] = $this->released();
        $this->assertInstanceOf(SalesJobExecutionContextVerifier::class, app(JobExecutionContextVerifier::class));
        $this->assertInstanceOf(SalesJobOrigins::class, app(JobSalesOrigin::class));
        $this->grantJobs($this->worker, ...self::JOB_DUTIES);
        $job = ServiceJob::query()->findOrFail($out['job_id']);
        $this->assertSame($out['release_event_id'], app(JobSalesOrigin::class)->releaseEventIdFor($job));

        $assigned = app(AssignJobResponsibility::class)->execute($this->checker, $job->id, $this->worker->id, 1, 'assign-1', JobLifecycleIntent::newCommandIdentity());
        $this->settle();
        $started = app(StartJob::class)->execute($this->checker, $job->id, $assigned->generation, 'start-1', JobLifecycleIntent::newCommandIdentity(), 'Go');
        $this->settle();
        $this->assertSame('ACTIVE', $started->state);
        $this->assertSame('ACTIVE', ServiceJob::query()->findOrFail($job->id)->state);

        // A fixture Job with no Sales lineage has no supported source integration: refused, never a "no impact" success.
        $raw = (int) DB::table('service_jobs')->insertGetId(['company_id' => 1, 'code' => 'JOB-RAW', 'state' => 'RELEASED', 'generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('job_events')->insert(['company_id' => 1, 'job_id' => $raw, 'sequence' => 1, 'operation' => 'RELEASE', 'from_state' => null, 'to_state' => 'RELEASED', 'prior_generation' => 0, 'next_generation' => 1,
            'occurrence_precision' => 'EXACT', 'recorded_at' => now(), 'actor_id' => $this->checker->id, 'evidence_version' => 1]);
        $this->settle();
        try {
            app(AssignJobResponsibility::class)->execute($this->checker, $raw, $this->worker->id, 1, 'assign-raw', JobLifecycleIntent::newCommandIdentity());
            $this->fail('An unsupported origin must refuse.');
        } catch (DomainInvariantException $e) {
            $this->assertSame('SOURCE_CONTEXT_UNAVAILABLE', $e->reasonCode);
        }
    }

    public function test_a_populated_migration_refuses_backout_before_dropping_anything(): void
    {
        $this->released();
        $migration = require base_path('database/migrations/2026_12_13_200000_create_job_receiver_foundation.php');
        try {
            DB::transaction(fn () => $migration->down());
            $this->fail('A populated store must refuse backout.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('erase released Job evidence', $e->getMessage());
        }
        foreach (['job_sales_origins', 'job_baseline_components', 'job_activities'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} survived the refused rollback");
            $this->assertSame(1, DB::table($table)->count());
        }
        $this->refused(fn () => DB::table('job_activities')->delete());
    }

    public function test_there_is_no_public_way_to_create_a_job(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());
        $this->assertSame([], $uris->filter(fn ($u) => preg_match('#^api/v1/(service-)?jobs#', $u) === 1)->values()->all());
        $shipped = new PermissionRegistry(app(PermissionRegistrar::class));
        JobDutyManifest::shipped()->registerWith($shipped);
        $this->assertSame([], array_values(array_filter($shipped->all(), fn ($p) => str_starts_with($p, 'job.'))));
    }
    // ---- assurance: the remaining contract checks (Phase 5 Batch 1 coverage map) ---------------------------------------------

    /** @param  callable(): mixed  $work an integrity refusal: SQLSTATE class 23, never a missing column or a malformed statement */
    private function refusedByIntegrity(callable $work): void
    {
        try {
            DB::transaction($work);
            $this->fail('The database must refuse this write.');
        } catch (QueryException $e) {
            $this->assertStringStartsWith('23', (string) $e->getCode(), 'an integrity refusal, not a malformed statement: '.$e->getMessage());
        }
    }

    /** A raw Job (no Sales lineage) with its RELEASE event, in a given company: building block for same-parent and cross-company attacks. */
    private function rawJob(string $code, int $company = 1): int
    {
        $job = (int) DB::table('service_jobs')->insertGetId(['company_id' => $company, 'code' => $code, 'state' => 'RELEASED', 'generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        if ($company === 1) {
            DB::table('job_events')->insert(['company_id' => 1, 'job_id' => $job, 'sequence' => 1, 'operation' => 'RELEASE', 'from_state' => null, 'to_state' => 'RELEASED', 'prior_generation' => 0, 'next_generation' => 1,
                'occurrence_precision' => 'EXACT', 'recorded_at' => now(), 'actor_id' => $this->checker->id, 'evidence_version' => 1]);
        }

        return $job;
    }

    /** @return array<string, mixed> the acceptance-claim evidence a release must never touch */
    private function claimState(): array
    {
        return ['heads' => DB::table('customer_scope_claim_heads')->orderBy('id')->get()->all(), 'acceptances' => DB::table('customer_acceptances')->orderBy('id')->get()->all(),
            'acceptance_lines' => DB::table('customer_acceptance_lines')->orderBy('id')->get()->all()];
    }

    private function injectCommitFailure(string $table): void
    {
        DB::unprepared("CREATE FUNCTION zz_inject_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'injected failure on %', TG_TABLE_NAME USING ERRCODE='P0001'; END \$\$");
        DB::unprepared("CREATE TRIGGER zz_inject AFTER INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION zz_inject_failure()");
    }

    private function removeInjectedFailure(string $table): void
    {
        DB::unprepared("DROP TRIGGER zz_inject ON {$table}");
        DB::unprepared('DROP FUNCTION zz_inject_failure()');
    }

    // 11 — a second stage, purpose or origin for the same accepted line cannot create a second Job

    public function test_no_second_stage_purpose_or_release_can_exist_for_the_same_issued_line_so_there_is_never_a_second_job(): void
    {
        [$out, $origin, $component] = $this->released();
        $release = (array) DB::table('handover_release_events')->first();
        unset($release['id']);
        $stage = (array) DB::table('handover_stages')->first();
        $allocation = (array) DB::table('handover_allocations')->first();
        $plan = (array) DB::table('handover_plans')->first();
        unset($stage['id'], $allocation['id'], $plan['id']);

        // another purpose for the same stage: the purpose vocabulary has exactly one member
        $this->refusedByIntegrity(fn () => DB::table('handover_release_events')->insert([...$release, 'purpose' => 'SECOND_PURPOSE', 'request_key' => 'other-purpose']));
        // the same stage and purpose again, whatever the key or actor
        $this->refusedByIntegrity(fn () => DB::table('handover_release_events')->insert([...$release, 'request_key' => 'fresh-key']));
        // a second stage for the allocation, a second allocation for the accepted line, a second plan for the acceptance
        $this->refusedByIntegrity(fn () => DB::table('handover_stages')->insert($stage));
        $this->refusedByIntegrity(fn () => DB::table('handover_allocations')->insert($allocation));
        $this->refusedByIntegrity(fn () => DB::table('handover_plans')->insert($plan));
        // a second Job (generic origin, Sales lineage) for the one release event, and a second component for the one baseline
        $job2 = $this->rawJob('JOB-SECOND');
        $this->refusedByIntegrity(fn () => DB::table('job_sales_origins')->insert(['company_id' => 1, 'job_id' => $job2, 'release_event_id' => $out['release_event_id']]));
        unset($component['id']);
        $this->refusedByIntegrity(fn () => DB::table('job_baseline_components')->insert([...$component, 'job_id' => $job2]));

        $line = $component['issued_line_id'];
        $this->assertSame(1, DB::table('job_baseline_components')->where('issued_line_id', $line)->count(), 'one component per issued line');
        $this->assertSame(1, DB::table('job_sales_origins')->count());
        $this->assertSame(1, DB::table('handover_release_events')->count());
        $this->assertSame(1, DB::table('numbering_allocations')->where('doc_type', 'JOB')->count(), 'one Job number');
    }

    // 8 — cross-company and same-parent attacks on the remaining Jobs tables

    public function test_the_database_refuses_cross_company_and_same_parent_rows_in_origins_baselines_and_events(): void
    {
        [$out] = $this->released();
        $other = (int) DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $origin = (array) DB::table('job_handover_origins')->where('job_id', $out['job_id'])->first();
        $baseline = (array) DB::table('job_baselines')->where('job_id', $out['job_id'])->first();
        $event = (array) DB::table('job_events')->where('job_id', $out['job_id'])->first();
        unset($origin['id'], $baseline['id'], $event['id']);
        $job2 = $this->rawJob('JOB-SECOND');
        $foreignJob = $this->rawJob('JOB-FOREIGN', $other);

        // the row's company differs from its Job's company
        $this->refusedByIntegrity(fn () => DB::table('job_handover_origins')->insert([...$origin, 'company_id' => $other, 'business_identity' => 'stage:9001']));
        $this->refusedByIntegrity(fn () => DB::table('job_baselines')->insert([...$baseline, 'company_id' => $other, 'version' => 7]));
        $this->refusedByIntegrity(fn () => DB::table('job_events')->insert([...$event, 'company_id' => $other, 'sequence' => 9, 'next_generation' => 9]));
        // a company-1 row naming a Job of another company
        $this->refusedByIntegrity(fn () => DB::table('job_handover_origins')->insert([...$origin, 'job_id' => $foreignJob, 'business_identity' => 'stage:9002']));
        $this->refusedByIntegrity(fn () => DB::table('job_baselines')->insert([...$baseline, 'job_id' => $foreignJob, 'version' => 7]));
        $this->refusedByIntegrity(fn () => DB::table('job_events')->insert([...$event, 'job_id' => $foreignJob, 'sequence' => 9, 'next_generation' => 9]));
        // same company, wrong parent: a baseline v2 of one Job cannot descend from another Job's baseline
        $this->refusedByIntegrity(fn () => DB::table('job_baselines')->insert([...$baseline, 'job_id' => $job2, 'version' => 2, 'predecessor_id' => DB::table('job_baselines')->where('job_id', $out['job_id'])->value('id')]));
        // a generic origin identity (purpose + business identity) is unique company-wide: the same stage cannot be received twice under another Job
        $this->refusedByIntegrity(fn () => DB::table('job_handover_origins')->insert([...$origin, 'job_id' => $job2]));
        // an event whose actor belongs to no member of that company
        $this->refusedByIntegrity(fn () => DB::table('job_events')->insert([...$event, 'job_id' => $job2, 'sequence' => 2, 'next_generation' => 2, 'actor_id' => 99999999]));
    }

    // 2 — authority after replay

    public function test_a_replay_after_job_receive_is_revoked_or_the_actor_is_suspended_is_refused_and_writes_nothing(): void
    {
        $this->recordBasis();
        $first = $this->release('K');
        $before = $this->counts();
        $claims = $this->claimState();

        $this->checker->revokePermissionTo('job.receive');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertRefusedAuthority(fn () => $this->release('K'));
        $this->assertRefusedAuthority(fn () => $this->release('K-fresh'));
        $this->assertSame($before, $this->counts(), 'a refused replay writes nothing');

        // re-granting restores exactly the original result: no second Job, no new number
        $this->checker->givePermissionTo('job.receive');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $again = $this->release('K');
        $this->assertTrue($again['replayed']);
        $this->assertSame([$first['job_id'], $first['job_code']], [$again['job_id'], $again['job_code']]);

        $this->checker->forceFill(['status' => 'SUSPENDED'])->save();
        $this->assertRefusedAuthority(fn () => $this->release('K'));
        $this->assertSame($before, $this->counts());
        $this->assertEquals($claims, $this->claimState());
    }

    // 10 — claim invariance under failure, replay, fresh key and a second actor

    public function test_acceptance_claims_stay_byte_equal_through_failure_replay_fresh_key_and_second_actor(): void
    {
        $this->recordBasis();
        $claims = $this->claimState();

        $this->injectCommitFailure('job_activities');
        try {
            $this->release('doomed');
            $this->fail('expected the injected failure');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        $this->removeInjectedFailure('job_activities');
        $this->assertEquals($claims, $this->claimState(), 'a rolled-back release leaves the claims untouched');

        $this->release('good');
        $this->release('good');
        $this->release('fresh');
        $second = User::factory()->create();
        $second->givePermissionTo($this->handoverDuties());
        $this->grantJobs($second, 'job.receive');
        $this->release('other', $second);
        $this->settle();
        $this->assertEquals($claims, $this->claimState(), 'release, replay, fresh key and a second actor never consume or move an acceptance claim');
    }

    // 12 — numbering after a rolled-back release

    public function test_a_rolled_back_release_does_not_burn_a_job_number(): void
    {
        $this->recordBasis();
        $counters = DB::table('numbering_counters')->orderBy('id')->get()->all();
        $this->injectCommitFailure('job_activities');
        try {
            $this->release('doomed');
            $this->fail('expected the injected failure');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        $this->removeInjectedFailure('job_activities');
        $this->assertEquals($counters, DB::table('numbering_counters')->orderBy('id')->get()->all(), 'the Job counter did not move');
        $this->assertSame(0, DB::table('numbering_allocations')->where('doc_type', 'JOB')->count());

        $out = $this->release('clean');
        $this->settle();
        $this->assertSame('JOB-00001', $out['job_code'], 'the first Job that commits gets the first number: no visible gap after the failed attempt');
    }

    // 3b — one connection, one transaction

    public function test_the_receiver_runs_on_the_release_connection_and_in_the_release_transaction(): void
    {
        $this->recordBasis();
        $real = app(ReceiveReleasedService::class);
        $seen = [];
        $this->app->instance(ReleasedServiceReceiver::class, new class($real, $seen) implements ReleasedServiceReceiver
        {
            public function __construct(private readonly ReceiveReleasedService $real, public array &$seen) {}

            public function receive(VerifiedServiceRelease $release): ReleasedServiceReference
            {
                $c = DB::connection();
                $probe = fn (): array => (array) $c->selectOne('select pg_backend_pid() as pid, pg_current_xact_id()::xid::text as xid');
                $this->seen['connection'] = spl_object_id($c);
                $this->seen['level'] = $c->transactionLevel();
                $this->seen['before'] = $probe();
                $reference = $this->real->receive($release);
                $this->seen['after'] = $probe();

                return $reference;
            }

            public function assertMayReceive(\App\Models\User $actor): void
            {
                $this->real->assertMayReceive($actor);
            }

            public function referenceFor(int $releaseEventId): ?ReleasedServiceReference
            {
                return $this->real->referenceFor($releaseEventId);
            }
        });
        $this->release();
        $this->settle();

        $this->assertSame(spl_object_id(DB::connection()), $seen['connection'], 'the receiver used the default connection');
        $this->assertGreaterThanOrEqual(1, $seen['level']);
        $this->assertSame($seen['before'], $seen['after'], 'no commit, new session or new transaction inside the receiver');
        $this->assertSame((int) DB::selectOne('select pg_backend_pid() as pid')->pid, (int) $seen['before']['pid'], 'the test session is the release session');
    }

    // 9b — the source verifier is entered before the first Job row lock

    public function test_the_sales_source_fences_are_taken_before_the_job_row_is_locked(): void
    {
        [$out] = $this->released();
        $this->grantJobs($this->worker, ...self::JOB_DUTIES);
        $order = [];
        $inner = app(JobExecutionContextVerifier::class);
        $this->app->instance(JobExecutionContextVerifier::class, new class($inner, $order) implements JobExecutionContextVerifier
        {
            public function __construct(private readonly JobExecutionContextVerifier $inner, public array &$order) {}

            public function enter(User $actor, ServiceJob $job, string $operation): JobExecutionContext
            {
                $this->order[] = 'verifier:start';
                $context = $this->inner->enter($actor, $job, $operation);
                $this->order[] = 'verifier:done';

                return $context;
            }
        });
        DB::listen(function ($query) use (&$order): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'for update')) {
                $order[] = 'lock:'.(str_contains($sql, '"sales_authority_heads"') ? 'authority-head' : (str_contains($sql, '"service_jobs"') ? 'job' : (str_contains($sql, '"customer_acceptances"') ? 'acceptance' : 'other')));
            }
        });
        app(AssignJobResponsibility::class)->execute($this->checker, $out['job_id'], $this->worker->id, 1, 'assign-order', JobLifecycleIntent::newCommandIdentity());

        $verifierEnd = array_search('verifier:done', $order, true);
        $jobLock = array_search('lock:job', $order, true);
        $this->assertNotFalse($verifierEnd, 'the verifier was entered');
        $this->assertNotFalse($jobLock, 'the Job row was locked');
        $this->assertLessThan($jobLock, $verifierEnd, 'every source fence is taken, and the verifier has returned, before the Job row lock: '.implode(' > ', $order));
        $this->assertLessThan($jobLock, array_search('lock:authority-head', $order, true), 'the Sales authority head is locked first');
        $this->assertLessThan($jobLock, array_search('lock:acceptance', $order, true));
    }
}
