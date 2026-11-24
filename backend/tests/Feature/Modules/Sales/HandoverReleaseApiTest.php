<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\User;
use App\Modules\Jobs\Support\Authority\JobDuty;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Authority\JobDutyStatus;
use App\Modules\Sales\Actions\PrepareHandoverPlan;
use App\Modules\Sales\Actions\PreviewHandover;
use App\Modules\Sales\Models\CustomerDecisionEvidence;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\Handover\HandoverSubject;
use App\Modules\Sales\Support\Handover\Release\CostRequirement;
use App\Modules\Sales\Support\Handover\Release\ReleaseCostPolicy;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Sales\Concerns\BuildsHandoverFixture;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\Support\Sales\ScopedReleaseFixtures;
use Tests\TestCase;

/**
 * Real HTTP integration of the three Sales release endpoints over the REAL Sales coordinator and REAL Jobs receiver: accepted service -> completion basis -> release preview ->
 * release -> exactly one numbered Job. The two-session race and deferred-COMMIT proofs already exist (HandoverReleaseRaceTest, JobReceiverReleaseRaceTest) and are not repeated.
 */
class HandoverReleaseApiTest extends TestCase
{
    use BuildsHandoverFixture;
    use BuildsIssuableQuotations;
    use RefreshDatabase;
    use ScopedReleaseFixtures;

    private const JOB_DUTIES = ['job.receive', 'job.assign', 'job.start'];

    private array $f;

    private int $stageId;

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
        foreach (self::JOB_DUTIES as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $this->checker->givePermissionTo(self::JOB_DUTIES);
        $this->f = $this->serviceAcceptance($this->checker, $this->customer->id);
        $token = app(PreviewHandover::class)($this->checker, $this->f['acceptance'], $this->f['inputs'])['preview_token'];
        app(PrepareHandoverPlan::class)($this->checker, 'prepare', $this->f['acceptance'], $this->f['inputs'], $token);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->stageId = (int) DB::table('handover_stages')->value('id');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->checker);
    }

    /** @param list<string> $names */
    private function activateJobs(array $names): void
    {
        $this->app->instance(JobDutyManifest::class, new JobDutyManifest(array_map(fn (string $n): JobDuty => new JobDuty($n, JobDutyStatus::Active, 'op', 'projection', 'gate'), $names)));
    }

    private function settle(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function basisUrl(): string
    {
        return '/api/v1/customer-acceptances/'.$this->f['acceptance'].'/completion-bases';
    }

    private function stageUrl(string $suffix, ?int $stage = null): string
    {
        return '/api/v1/handover-stages/'.($stage ?? $this->stageId).'/'.$suffix;
    }

    /** A basis through HTTP on its own dedicated written evidence, with an exact scope declaration. */
    private function postBasis(string $key = 'basis', string $materials = 'EXCLUDED', string $subcontract = 'EXCLUDED'): TestResponse
    {
        $full = $this->scopeDeclaration($this->f['acceptance'], $materials, $subcontract);
        $evidence = $this->scopedEvidence[$full] ??= $this->appendAgreementEvidence($this->f['case'], $this->checker->id, $full);

        return $this->postJson($this->basisUrl(), ['agreed_text' => $full, 'evidence_id' => $evidence], ['Idempotency-Key' => $key]);
    }

    private function preview(): array
    {
        return $this->getJson($this->stageUrl('release-preview'))->assertOk()->json('data');
    }

    private function postRelease(array $p, string $key = 'release', ?array $override = null): TestResponse
    {
        return $this->postJson($this->stageUrl('release'), $override ?? ['basis_id' => $p['basis_id'], 'readiness_fingerprint' => $p['fingerprint']], ['Idempotency-Key' => $key]);
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        $out = [];
        foreach (['handover_release_events', 'service_jobs', 'job_events', 'job_baselines', 'job_handover_origins', 'job_sales_origins', 'job_baseline_components', 'job_activities', 'numbering_allocations', 'service_completion_bases'] as $t) {
            $out[$t] = DB::table($t)->count();
        }

        return $out;
    }

    public function test_accepted_service_to_basis_to_preview_to_release_creates_exactly_one_numbered_job_with_minimal_responses(): void
    {
        $basis = $this->postBasis()->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.replayed', false);
        $this->assertSame(['basis_id', 'replayed', 'version'], $this->sorted($basis->json('data')));
        $this->postBasis()->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.basis_id', $basis->json('data.basis_id'));

        $p = $this->preview();
        $this->assertTrue($p['ready']);
        $this->assertSame([], $p['blockers']);
        $this->assertSame($basis->json('data.basis_id'), $p['basis_id']);
        $this->assertSame(['status' => 'NOT_APPLICABLE', 'materials' => 'EXCLUDED', 'subcontract' => 'EXCLUDED'], $p['applicability']);
        $this->assertSame(['applicability', 'basis_id', 'basis_version', 'blockers', 'cost', 'fingerprint', 'next_actions', 'ready'], $this->sorted($p));
        $this->assertSame(['required', 'status'], $this->sorted($p['cost']));
        $this->assertSame(0, DB::table('service_jobs')->count(), 'a preview writes nothing');

        $out = $this->postRelease($p)->assertCreated()->assertJsonPath('data.replayed', false);
        $this->settle();
        $data = $out->json('data');
        $this->assertSame(['job_code', 'job_id', 'release_event_id', 'replayed'], $this->sorted($data));
        $this->assertMatchesRegularExpression('/^JOB-\d{5}$/', $data['job_code']);
        $this->assertSame(1, DB::table('service_jobs')->count());
        $this->assertSame($data['job_code'], DB::table('service_jobs')->where('id', $data['job_id'])->value('code'));
        $this->assertSame(1, DB::table('handover_release_events')->count());
        $this->assertSame(1, DB::table('job_sales_origins')->count());

        $before = $this->counts();
        foreach (['release', 'other-key'] as $key) {
            // the exact key replays (200 + header); a fresh key of the same business intent resolves to the SAME release and Job (the Action reports it as not replayed)
            $again = $this->postRelease($p, $key);
            $again->assertStatus($key === 'release' ? 200 : 201);
            $this->assertSame([$data['job_id'], $data['job_code'], $data['release_event_id']], [$again->json('data.job_id'), $again->json('data.job_code'), $again->json('data.release_event_id')]);
        }
        $this->assertSame($before, $this->counts(), 'no second Job, number or release');
        $this->assertStringNotContainsString('agreed_text', $out->getContent());
    }

    public function test_unauthenticated_staged_and_missing_duties_are_refused_before_any_write(): void
    {
        $before = $this->counts();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->stageUrl('release-preview'))->assertUnauthorized();
        $this->postJson($this->stageUrl('release'), [])->assertUnauthorized();
        $this->postJson($this->basisUrl(), [])->assertUnauthorized();

        Sanctum::actingAs($this->checker);
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->getJson($this->stageUrl('release-preview'))->assertForbidden();
        $this->postJson($this->stageUrl('release'), [])->assertForbidden();
        $this->postJson($this->basisUrl(), [])->assertForbidden();
        $this->assertNotContains(P::HANDOVER_RELEASE, P::all());
        $this->assertSame($before, $this->counts());
    }

    public function test_each_exact_duty_is_required_and_the_acceptance_view_duty_too(): void
    {
        $this->postBasis()->assertCreated();
        $p = $this->preview();
        $this->checker->revokePermissionTo(P::HANDOVER_RELEASE);
        $this->getJson($this->stageUrl('release-preview'))->assertForbidden();
        $this->postRelease($p)->assertForbidden();
        $this->checker->givePermissionTo(P::HANDOVER_RELEASE);
        $this->checker->revokePermissionTo(P::HANDOVER_COMPLETION_BASIS_RECORD);
        $this->postBasis('another')->assertForbidden();
        $this->checker->givePermissionTo(P::HANDOVER_COMPLETION_BASIS_RECORD);
        $this->checker->revokePermissionTo(P::CUSTOMER_DECISION_VIEW);
        $this->getJson($this->stageUrl('release-preview'))->assertForbidden();
        $this->postRelease($p)->assertForbidden();
        $this->postBasis('another')->assertForbidden();
        $this->checker->givePermissionTo(P::CUSTOMER_DECISION_VIEW);
        $this->checker->revokePermissionTo(P::QUOTATION_VIEW);
        $this->getJson($this->stageUrl('release-preview'))->assertForbidden();
        $this->assertSame(0, DB::table('service_jobs')->count());
    }

    public function test_job_receive_is_required_independently_of_every_sales_duty(): void
    {
        $this->postBasis()->assertCreated();
        $p = $this->preview();
        $before = $this->counts();
        $this->checker->revokePermissionTo('job.receive');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->postRelease($p)->assertForbidden();
        $this->getJson($this->stageUrl('release-preview'))->assertOk();
        $this->assertSame($before, $this->counts());
        $this->checker->givePermissionTo('job.receive');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->postRelease($p)->assertCreated();
        $this->settle();
        $this->assertSame(1, DB::table('service_jobs')->count());
    }

    public function test_foreign_company_and_unknown_records_are_not_found_for_all_three_endpoints(): void
    {
        $this->postBasis()->assertCreated();
        $p = $this->preview();
        $this->getJson($this->stageUrl('release-preview', 999999))->assertNotFound();
        $this->postJson('/api/v1/handover-stages/999999/release', ['basis_id' => 1, 'readiness_fingerprint' => 'x'], ['Idempotency-Key' => 'n'])->assertNotFound();
        $this->postJson('/api/v1/customer-acceptances/999999/completion-bases', ['agreed_text' => null, 'evidence_id' => 1], ['Idempotency-Key' => 'n'])->assertNotFound();

        $company = DB::table('companies')->insertGetId(['name' => 'Synthetic foreign company', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($company) extends CompanyContext
        {
            public function __construct(private readonly int $company) {}

            public function id(): int
            {
                return $this->company;
            }
        });
        app(PermissionRegistrar::class)->setPermissionsTeamId($company);
        $actor = User::factory()->create();
        $actor->givePermissionTo(array_merge($this->handoverDuties(), self::JOB_DUTIES));
        Sanctum::actingAs($actor);
        $this->getJson($this->stageUrl('release-preview'))->assertNotFound();
        $this->postJson($this->stageUrl('release'), ['basis_id' => $p['basis_id'], 'readiness_fingerprint' => $p['fingerprint']], ['Idempotency-Key' => 'f'])->assertNotFound();
        $this->postJson($this->basisUrl(), ['agreed_text' => null, 'evidence_id' => 1], ['Idempotency-Key' => 'f'])->assertNotFound();
        $this->assertSame(0, DB::table('service_jobs')->count());
    }

    public function test_missing_evidence_and_bad_bodies_are_refused_and_write_nothing(): void
    {
        $before = $this->counts();
        $this->postJson($this->basisUrl(), ['agreed_text' => null], ['Idempotency-Key' => 'a'])->assertUnprocessable()->assertJsonValidationErrors('evidence_id');
        $this->postJson($this->basisUrl(), ['evidence_id' => 1], ['Idempotency-Key' => 'a'])->assertUnprocessable()->assertJsonValidationErrors('agreed_text');
        $this->postJson($this->basisUrl(), ['agreed_text' => null, 'evidence_id' => 1, 'materials_scope' => 'EXCLUDED'], ['Idempotency-Key' => 'a'])->assertUnprocessable()->assertJsonValidationErrors('materials_scope');
        $this->postJson($this->basisUrl(), ['agreed_text' => null, 'evidence_id' => 1])->assertUnprocessable()->assertJsonPath('reason_code', 'REQUEST_KEY_REQUIRED');
        $this->postJson($this->basisUrl(), ['agreed_text' => null, 'evidence_id' => 999999], ['Idempotency-Key' => 'a'])->assertUnprocessable()->assertJsonPath('reason_code', 'INVALID_INPUT');
        // verbal and unverified evidence are not genuine written, authority-verified customer evidence
        $verbal = $this->appendAgreementEvidence($this->f['case'], $this->checker->id, 'verbal', 'VERBAL');
        $unverified = $this->appendAgreementEvidence($this->f['case'], $this->checker->id, 'unverified', 'EMAIL', 'UNVERIFIED');
        foreach ([$verbal, $unverified] as $i => $evidence) {
            $this->postJson($this->basisUrl(), ['agreed_text' => 'x', 'evidence_id' => $evidence], ['Idempotency-Key' => 'e'.$i])->assertUnprocessable()->assertJsonPath('reason_code', 'INVALID_INPUT');
        }
        // free text that does not equal the evidence, and new conditions riding on the acceptance's own consent evidence
        $own = (int) DB::table('customer_decision_evidence')->where('case_id', $this->f['case'])->orderBy('id')->value('id');
        $this->postJson($this->basisUrl(), ['agreed_text' => 'Brand new obligation', 'evidence_id' => $own], ['Idempotency-Key' => 'own'])->assertUnprocessable()->assertJsonPath('reason_code', 'INVALID_INPUT');
        $dedicated = $this->appendAgreementEvidence($this->f['case'], $this->checker->id, 'Something else entirely');
        $this->postJson($this->basisUrl(), ['agreed_text' => 'Not the evidence text', 'evidence_id' => $dedicated], ['Idempotency-Key' => 'mismatch'])->assertUnprocessable()->assertJsonPath('reason_code', 'INVALID_INPUT');
        $this->assertSame($before, $this->counts());
        $this->assertSame(0, DB::table('service_completion_bases')->count());
    }

    public function test_an_invalid_scope_declaration_is_refused(): void
    {
        $good = $this->scopeDeclaration($this->f['acceptance']);
        $lines = explode("\n", $good);
        $cases = [
            'extra free text' => $good."\nAlso we promise gold plating.",
            'reordered' => implode("\n", [$lines[1], $lines[0], $lines[2], $lines[3]]),
            'wrong digest' => preg_replace('/DELIVERABLE_SHA256: ?\S+/', 'DELIVERABLE_SHA256: '.str_repeat('0', 64), $good),
            'wrong line' => preg_replace('/LINE: ?\d+/', 'LINE: 999999', $good),
            'bad value' => preg_replace('/MATERIALS: ?\S+/', 'MATERIALS: MAYBE', $good),
        ];
        $i = 0;
        foreach ($cases as $label => $text) {
            $evidence = $this->appendAgreementEvidence($this->f['case'], $this->checker->id, $text);
            $this->postJson($this->basisUrl(), ['agreed_text' => $text, 'evidence_id' => $evidence], ['Idempotency-Key' => 'inv'.$i++])->assertUnprocessable()->assertJsonPath('reason_code', 'INVALID_INPUT');
        }
        $this->assertSame(0, DB::table('service_completion_bases')->count());
    }

    public function test_with_no_basis_the_preview_blocks_and_release_is_refused_and_writes_nothing(): void
    {
        $p = $this->preview();
        $this->assertFalse($p['ready']);
        $this->assertContains('COMPLETION_BASIS_MISSING', $p['blockers']);
        $this->assertNull($p['basis_id']);
        $before = $this->counts();
        $this->postRelease($p, 'r', ['basis_id' => 1, 'readiness_fingerprint' => $p['fingerprint']])->assertUnprocessable()->assertJsonPath('reason_code', 'COMPLETION_BASIS_MISSING');
        $this->postRelease($p, 'r2', ['basis_id' => 0, 'readiness_fingerprint' => $p['fingerprint']])->assertUnprocessable()->assertJsonValidationErrors('basis_id');
        $this->assertSame($before, $this->counts());
    }

    public function test_unknown_applicability_blocks_release_whatever_the_service_item_or_empty_queries_say(): void
    {
        // the lightweight default: the acceptance's own consent evidence, issued description verbatim, states no scope
        $own = (int) DB::table('customer_decision_evidence')->where('case_id', $this->f['case'])->orderBy('id')->value('id');
        $this->postJson($this->basisUrl(), ['agreed_text' => null, 'evidence_id' => $own], ['Idempotency-Key' => 'default'])->assertCreated();
        $p = $this->preview();
        $this->assertFalse($p['ready']);
        $this->assertContains('SOURCE_APPLICABILITY_UNPROVEN', $p['blockers']);
        $this->assertSame('UNKNOWN', $p['applicability']['status']);
        $before = $this->counts();
        $this->postRelease($p)->assertUnprocessable()->assertJsonPath('reason_code', 'HANDOVER_NOT_READY');
        $this->assertSame($before, $this->counts());
        $this->assertStringNotContainsString('TEST_NO_JOB_BINDING', json_encode($p));
    }

    public function test_a_mandatory_cost_control_without_evidence_blocks_release(): void
    {
        $this->postBasis()->assertCreated();
        $this->app->instance(ReleaseCostPolicy::class, new class implements ReleaseCostPolicy
        {
            public function requirementFor(HandoverSubject $subject): CostRequirement
            {
                return new CostRequirement(true, 'UNKNOWN', 'TEST_MANDATORY.1');
            }
        });
        $p = $this->preview();
        $this->assertContains('SERVICE_COST_EVIDENCE_REQUIRED', $p['blockers']);
        $this->assertSame(['required' => true, 'status' => 'UNKNOWN'], $p['cost']);
        $this->assertStringNotContainsString('TEST_MANDATORY', json_encode($p));
        $this->postRelease($p)->assertUnprocessable()->assertJsonPath('reason_code', 'HANDOVER_NOT_READY');
        $this->assertSame(0, DB::table('handover_release_events')->count());
    }

    public function test_a_stale_preview_is_refused_when_the_basis_or_the_facts_change(): void
    {
        $this->postBasis()->assertCreated();
        $old = $this->preview();
        // a new head basis (new agreement evidence) after review
        $text = $this->scopeDeclaration($this->f['acceptance']);
        $second = $this->appendAgreementEvidence($this->f['case'], $this->checker->id, $text);
        $this->postJson($this->basisUrl(), ['agreed_text' => $text, 'evidence_id' => $second], ['Idempotency-Key' => 'v2'])->assertCreated()->assertJsonPath('data.version', 2);
        $this->postRelease($old, 'stale1')->assertStatus(409)->assertJsonPath('reason_code', 'PREVIEW_TOKEN_STALE');
        $fresh = $this->preview();
        $this->postRelease(['basis_id' => $fresh['basis_id']] + $old + [], 'stale2', ['basis_id' => $fresh['basis_id'], 'readiness_fingerprint' => $old['fingerprint']])->assertStatus(409)->assertJsonPath('reason_code', 'PREVIEW_TOKEN_STALE');
        // the source facts change (a source family stops proving NOT_APPLICABLE)
        $this->registerSourceFamilies('UNKNOWN');
        $this->postRelease($fresh, 'stale3')->assertStatus(409)->assertJsonPath('reason_code', 'PREVIEW_TOKEN_STALE');
        $this->assertSame(0, DB::table('handover_release_events')->count());
    }

    public function test_a_changed_intent_under_a_used_key_and_a_missing_key_are_refused(): void
    {
        $this->postBasis()->assertCreated();
        $p = $this->preview();
        $this->postJson($this->stageUrl('release'), ['basis_id' => $p['basis_id'], 'readiness_fingerprint' => $p['fingerprint']])->assertUnprocessable()->assertJsonPath('reason_code', 'REQUEST_KEY_REQUIRED');
        $this->postRelease($p, 'same')->assertCreated();
        $this->settle();
        $this->postRelease($p, 'same', ['basis_id' => $p['basis_id'], 'readiness_fingerprint' => str_repeat('f', 64)])->assertStatus(409)->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');
        $this->postJson($this->basisUrl(), ['agreed_text' => 'different', 'evidence_id' => 1], ['Idempotency-Key' => 'basis-key'])->assertUnprocessable();
        $this->assertSame(1, DB::table('service_jobs')->count());
    }

    public function test_a_second_release_with_another_basis_or_key_never_creates_a_second_job(): void
    {
        $this->postBasis()->assertCreated();
        $p = $this->preview();
        $this->postRelease($p, 'one')->assertCreated();
        $this->settle();
        $text = $this->scopeDeclaration($this->f['acceptance']);
        $evidence = $this->appendAgreementEvidence($this->f['case'], $this->checker->id, $text);
        $v2 = $this->postJson($this->basisUrl(), ['agreed_text' => $text, 'evidence_id' => $evidence], ['Idempotency-Key' => 'v2'])->assertCreated()->json('data.basis_id');
        $fp = $this->preview()['fingerprint'];
        $this->postRelease([], 'two', ['basis_id' => $v2, 'readiness_fingerprint' => $fp])->assertStatus(409)->assertJsonPath('reason_code', 'RELEASE_ALREADY_RECORDED');
        $this->assertSame(1, DB::table('service_jobs')->count());
        $this->assertSame(1, DB::table('handover_release_events')->count());
    }

    public function test_a_failure_after_each_receiver_write_rolls_the_whole_release_back_through_http(): void
    {
        $this->postBasis()->assertCreated();
        $p = $this->preview();
        $before = $this->counts();
        DB::unprepared("CREATE FUNCTION zz_inject_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'injected failure on %', TG_TABLE_NAME USING ERRCODE='P0001'; END \$\$");
        foreach (['service_jobs', 'job_events', 'job_baselines', 'job_sales_origins', 'job_baseline_components', 'job_activities', 'numbering_allocations'] as $table) {
            DB::unprepared("CREATE TRIGGER zz_inject AFTER INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION zz_inject_failure()");
            $this->postRelease($p, 'inject-'.$table)->assertStatus(500);
            DB::unprepared("DROP TRIGGER zz_inject ON {$table}");
            $this->assertSame($before, $this->counts(), "nothing survived a failure after the {$table} write");
        }
        DB::unprepared('DROP FUNCTION zz_inject_failure()');
        $this->postRelease($p, 'clean')->assertCreated();
        $this->settle();
        $this->assertSame(1, DB::table('service_jobs')->count(), 'a clean retry creates exactly one Job after the failed attempts');
    }

    /** @return list<string> */
    /** A later observation that corrects an earlier one of the same case (the chain never forks). */
    private function correct(int $predecessor, string $text): int
    {
        return (int) CustomerDecisionEvidence::create(['case_id' => $this->f['case'], 'predecessor_id' => $predecessor, 'source_kind' => 'EMAIL', 'authority_verification_basis' => 'DOCUMENT_ON_FILE',
            'customer_representative_snapshot' => 'Synthetic buyer', 'occurrence_precision' => 'UNKNOWN', 'factual_description' => $text, 'observation_state' => 'OBSERVED', 'recorded_by' => $this->checker->id])->id;
    }

    public function test_a_corrected_observation_cannot_be_cited_as_new_agreement_evidence(): void
    {
        $excluded = $this->scopeDeclaration($this->f['acceptance']);
        $original = $this->appendAgreementEvidence($this->f['case'], $this->checker->id, $excluded);
        $corrected = $this->correct($original, $this->scopeDeclaration($this->f['acceptance'], 'REQUIRED', 'EXCLUDED'));

        $before = $this->counts();
        $this->postJson($this->basisUrl(), ['agreed_text' => $excluded, 'evidence_id' => $original], ['Idempotency-Key' => 'old'])
            ->assertUnprocessable()->assertJsonPath('reason_code', 'INVALID_INPUT');
        $this->assertSame($before, $this->counts());

        // the current observation is accepted, and what it says (materials REQUIRED) is what applies
        $this->postJson($this->basisUrl(), ['agreed_text' => $this->scopeDeclaration($this->f['acceptance'], 'REQUIRED', 'EXCLUDED'), 'evidence_id' => $corrected], ['Idempotency-Key' => 'new'])->assertCreated();
        $p = $this->preview();
        $this->assertFalse($p['ready']);
        $this->assertContains('SOURCE_OBLIGATION_UNSUPPORTED', $p['blockers']);
        $this->assertNotContains('COMPLETION_EVIDENCE_SUPERSEDED', $p['blockers']);
    }

    public function test_a_correction_after_recording_and_before_release_blocks_the_release_and_the_old_review_goes_stale(): void
    {
        $this->postBasis()->assertCreated();
        $old = $this->preview();
        $this->assertTrue($old['ready']);
        $evidence = (int) DB::table('service_completion_bases')->value('evidence_id');
        $corrected = $this->correct($evidence, $this->scopeDeclaration($this->f['acceptance']));

        $p = $this->preview();
        $this->assertFalse($p['ready']);
        $this->assertContains('COMPLETION_EVIDENCE_SUPERSEDED', $p['blockers']);
        $this->assertNotSame($old['fingerprint'], $p['fingerprint']);
        $this->postRelease($old, 'r-old')->assertStatus(409)->assertJsonPath('reason_code', 'PREVIEW_TOKEN_STALE');
        $this->postRelease($p, 'r-blocked')->assertUnprocessable()->assertJsonPath('reason_code', 'HANDOVER_NOT_READY');
        $this->assertSame(0, DB::table('handover_release_events')->count());

        // citing the current observation in a new basis version restores readiness; the superseded row stays as history
        $this->postJson($this->basisUrl(), ['agreed_text' => $this->scopeDeclaration($this->f['acceptance']), 'evidence_id' => $corrected], ['Idempotency-Key' => 'v2'])->assertCreated()->assertJsonPath('data.version', 2);
        $fresh = $this->preview();
        $this->assertTrue($fresh['ready']);
        $this->postRelease($fresh, 'r-ok')->assertCreated();
        $this->assertSame(2, DB::table('service_completion_bases')->count());
        $this->assertSame(1, DB::table('customer_decision_evidence')->where('id', $evidence)->count());
    }

    public function test_a_correction_after_a_release_changes_neither_the_release_nor_the_job(): void
    {
        $this->postBasis()->assertCreated();
        $p = $this->preview();
        $out = $this->postRelease($p)->assertCreated()->json('data');
        $this->settle();
        $this->correct((int) DB::table('service_completion_bases')->value('evidence_id'), $this->scopeDeclaration($this->f['acceptance'], 'REQUIRED', 'REQUIRED'));
        $before = $this->counts();

        $again = $this->postRelease($p, 'later-key')->assertCreated();
        $this->assertSame([$out['job_id'], $out['job_code']], [$again->json('data.job_id'), $again->json('data.job_code')]);
        $this->assertSame($before, $this->counts());
    }

    private function statusUrl(?int $acceptance = null): string
    {
        return '/api/v1/customer-acceptances/'.($acceptance ?? $this->f['acceptance']).'/handover';
    }

    private function conditionsUrl(?int $acceptance = null): string
    {
        return '/api/v1/customer-acceptances/'.($acceptance ?? $this->f['acceptance']).'/completion-basis';
    }

    public function test_an_acceptance_discovers_its_own_prepared_handover_and_nothing_else(): void
    {
        $this->getJson($this->statusUrl())->assertOk()->assertExactJson(['data' => ['acceptance_id' => $this->f['acceptance'], 'prepared' => true,
            'plan_id' => (int) DB::table('handover_plans')->value('id'), 'stage_id' => $this->stageId, 'release_performed' => false]]);

        // a second accepted service with no preparation says so, and does not create one
        $other = $this->serviceAcceptance($this->checker, $this->customer->id, 'SERVICE_ITEM', '-b');
        $this->settle();
        $plans = DB::table('handover_plans')->count();
        $this->getJson($this->statusUrl($other['acceptance']))->assertOk()->assertExactJson(['data' => ['acceptance_id' => $other['acceptance'], 'prepared' => false,
            'plan_id' => null, 'stage_id' => null, 'release_performed' => false]]);
        $this->assertSame($plans, DB::table('handover_plans')->count());

        $this->getJson($this->statusUrl(999999))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->statusUrl())->assertUnauthorized();
        Sanctum::actingAs($this->checker);
        $this->checker->revokePermissionTo(P::CUSTOMER_DECISION_VIEW);
        $this->getJson($this->statusUrl())->assertForbidden();
        $this->checker->givePermissionTo(P::CUSTOMER_DECISION_VIEW);
        $this->checker->revokePermissionTo(P::QUOTATION_VIEW);
        $this->getJson($this->statusUrl())->assertForbidden();
        // the status needs neither the prepare nor the release duty (it reuses the acceptance and handover read rules, no broad directory, no new duty)
        $this->checker->givePermissionTo(P::QUOTATION_VIEW);
        $this->checker->revokePermissionTo([P::HANDOVER_PREPARE, P::HANDOVER_RELEASE, P::HANDOVER_COMPLETION_BASIS_RECORD]);
        $this->getJson($this->statusUrl())->assertOk()->assertJsonPath('data.prepared', true);
    }

    public function test_the_status_and_plan_come_from_the_immutable_release_evidence_and_the_job_only_to_those_who_may_see_jobs(): void
    {
        $this->activateJobs([...self::JOB_DUTIES, 'job.view']);
        Permission::findOrCreate('job.view', 'web');
        $planId = (int) DB::table('handover_plans')->value('id');
        $plan = fn (): array => $this->getJson('/api/v1/handover-plans/'.$planId)->assertOk()->json('data');

        $before = $plan();
        $this->assertFalse($before['release_performed']);
        $this->assertNull($before['release']);
        $this->postBasis()->assertCreated();
        $out = $this->postRelease($this->preview())->assertCreated()->json('data');
        $this->settle();
        $preparation = DB::table('handover_stages')->first();

        // without job.view: released, but no Job reference
        $d = $plan();
        $this->assertTrue($d['release_performed']);
        $this->assertSame('PREPARED', $d['state'], 'the append-only preparation record is not rewritten');
        $this->assertSame(['release_event_id' => $out['release_event_id'], 'released_at' => $d['release']['released_at'], 'job_access' => 'NOT_PERMITTED', 'job' => null], $d['release']);
        $this->assertStringNotContainsString($out['job_code'], json_encode($d));

        // with job.view: the existing Job's number and id
        $this->checker->givePermissionTo('job.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $d = $plan();
        $this->assertSame(['id' => $out['job_id'], 'code' => $out['job_code']], $d['release']['job']);
        $this->assertSame('VISIBLE', $d['release']['job_access']);
        $this->getJson($this->statusUrl())->assertOk()->assertJsonPath('data.release_performed', true);

        // revoking it again withdraws the reference immediately
        $this->checker->revokePermissionTo('job.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertNull($plan()['release']['job']);
        $this->assertEquals($preparation, DB::table('handover_stages')->first());
        $this->assertSame(1, DB::table('service_jobs')->count());
    }

    public function test_the_current_agreed_conditions_are_readable_by_recorders_and_releasers_only_and_follow_the_head(): void
    {
        $this->getJson($this->conditionsUrl())->assertOk()->assertExactJson(['data' => ['acceptance_id' => $this->f['acceptance'], 'current' => null]]);
        $first = $this->postBasis()->assertCreated()->json('data');
        $declaration = $this->scopeDeclaration($this->f['acceptance']);
        $c = $this->getJson($this->conditionsUrl())->assertOk()->json('data.current');
        $this->assertSame(['agreed_text', 'basis_id', 'evidence_current', 'materials_scope', 'recorded_at', 'subcontract_scope', 'terms_source', 'version'], $this->sorted($c));
        $this->assertSame([$first['basis_id'], 1, 'EVIDENCE_TEXT', $declaration, 'EXCLUDED', 'EXCLUDED', true], [$c['basis_id'], $c['version'], $c['terms_source'], $c['agreed_text'], $c['materials_scope'], $c['subcontract_scope'], $c['evidence_current']]);

        // a correction of the evidence is visible as such, and a new version becomes the head
        $evidence = (int) DB::table('service_completion_bases')->value('evidence_id');
        $corrected = $this->correct($evidence, $declaration);
        $this->getJson($this->conditionsUrl())->assertOk()->assertJsonPath('data.current.evidence_current', false);
        $this->postJson($this->basisUrl(), ['agreed_text' => $declaration, 'evidence_id' => $corrected], ['Idempotency-Key' => 'v2'])->assertCreated();
        $this->getJson($this->conditionsUrl())->assertOk()->assertJsonPath('data.current.version', 2)->assertJsonPath('data.current.evidence_current', true);

        // each reading duty alone suffices; with neither (or without the views) it is refused; foreign and unknown records are 404
        $this->checker->revokePermissionTo(P::HANDOVER_RELEASE);
        $this->getJson($this->conditionsUrl())->assertOk();
        $this->checker->givePermissionTo(P::HANDOVER_RELEASE);
        $this->checker->revokePermissionTo(P::HANDOVER_COMPLETION_BASIS_RECORD);
        $this->getJson($this->conditionsUrl())->assertOk();
        $this->checker->revokePermissionTo(P::HANDOVER_RELEASE);
        $this->getJson($this->conditionsUrl())->assertForbidden();
        $this->checker->givePermissionTo([P::HANDOVER_RELEASE, P::HANDOVER_COMPLETION_BASIS_RECORD]);
        $this->checker->revokePermissionTo(P::CUSTOMER_DECISION_VIEW);
        $this->getJson($this->conditionsUrl())->assertForbidden();
        $this->checker->givePermissionTo(P::CUSTOMER_DECISION_VIEW);
        $this->getJson($this->conditionsUrl(999999))->assertNotFound();
        $this->assertStringNotContainsString('evidence_id', $this->getJson($this->conditionsUrl())->getContent());
    }

    public function test_status_and_conditions_do_not_cross_companies(): void
    {
        $this->postBasis()->assertCreated();
        $company = DB::table('companies')->insertGetId(['name' => 'Synthetic foreign company', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($company) extends CompanyContext
        {
            public function __construct(private readonly int $company) {}

            public function id(): int
            {
                return $this->company;
            }
        });
        app(PermissionRegistrar::class)->setPermissionsTeamId($company);
        $actor = User::factory()->create();
        $actor->givePermissionTo(array_merge($this->handoverDuties(), self::JOB_DUTIES));
        Sanctum::actingAs($actor);
        $this->getJson($this->statusUrl())->assertNotFound();
        $this->getJson($this->conditionsUrl())->assertNotFound();
        $this->getJson('/api/v1/handover-plans/'.(int) DB::table('handover_plans')->value('id'))->assertNotFound();
    }

    private function sorted(array $a): array
    {
        $keys = array_keys($a);
        sort($keys);

        return $keys;
    }
}
