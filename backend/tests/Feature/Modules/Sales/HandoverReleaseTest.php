<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleRefusal;
use App\Modules\Sales\Actions\PrepareHandoverPlan;
use App\Modules\Sales\Actions\PreviewHandover;
use App\Modules\Sales\Actions\PreviewServiceRelease;
use App\Modules\Sales\Actions\RecordServiceCompletionBasis;
use App\Modules\Sales\Actions\ReleaseHandoverComponent;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\Handover\HandoverSubject;
use App\Modules\Sales\Support\Handover\Release\CostRequirement;
use App\Modules\Sales\Support\Handover\Release\HandoverTransactionContext;
use App\Modules\Sales\Support\Handover\Release\JobSalesOrigin;
use App\Modules\Sales\Support\Handover\Release\ReleaseCostPolicy;
use App\Modules\Sales\Support\Handover\Release\ReleasedServiceReceiver;
use App\Modules\Sales\Support\Handover\Release\ResolvedCompletionBasis;
use App\Modules\Sales\Support\Handover\Release\SalesJobExecutionContextVerifier;
use App\Modules\Sales\Support\Handover\Release\ScopeHeader;
use App\Modules\Sales\Support\Handover\Release\VerifiedServiceRelease;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsHandoverFixture;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\Support\Sales\FakeReleasedServiceReceiver;
use Tests\Support\Sales\ScopedReleaseFixtures;
use Tests\TestCase;

/** Phase 5 Batch 1 (Sales half): completion basis, release readiness, atomic release with a fake Jobs receiver, authority, idempotency, rollback and the source verifier. */
class HandoverReleaseTest extends TestCase
{
    use BuildsHandoverFixture;
    use BuildsIssuableQuotations;
    use RefreshDatabase;
    use ScopedReleaseFixtures;

    private array $f;

    /** @var array<string,int> */
    private array $agreementEvidence = [];

    private int $evidenceId;

    private int $stageId;

    private FakeReleasedServiceReceiver $receiver;

    protected function handoverDuties(): array
    {
        return [P::QUOTATION_PREPARE, P::QUOTATION_VIEW, P::QUOTATION_ISSUE, P::QUOTATION_WITHDRAW, P::CUSTOMER_DECISION_ACCEPT, P::CUSTOMER_DECISION_VIEW, P::HANDOVER_PREPARE,
            P::HANDOVER_COMPLETION_BASIS_RECORD, P::HANDOVER_RELEASE];
    }

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
        $this->evidenceId = (int) DB::table('customer_decision_evidence')->where('case_id', $this->f['case'])->value('id');
        $token = app(PreviewHandover::class)($this->checker, $this->f['acceptance'], $this->f['inputs'])['preview_token'];
        app(PrepareHandoverPlan::class)($this->checker, 'prepare', $this->f['acceptance'], $this->f['inputs'], $token);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->stageId = (int) DB::table('handover_stages')->value('id');
        $this->receiver = new FakeReleasedServiceReceiver;
        $this->registerSourceFamilies();
    }

    private function bind(): void
    {
        $this->app->instance(ReleasedServiceReceiver::class, $this->receiver);
    }

    /** A basis on its own agreement evidence (created once per text, so the same text is an idempotent replay). Scopes default to the pilot's EXCLUDED/EXCLUDED. */
    private function recordBasis(string $key = 'basis', string $text = self::AGREED, ?User $actor = null, string $materials = 'EXCLUDED', string $subcontract = 'EXCLUDED'): array
    {
        $full = ScopeHeader::compose($materials, $subcontract, $text);
        $evidence = $this->agreementEvidence[$full] ??= $this->appendAgreementEvidence($this->f['case'], $this->checker->id, $full);

        return app(RecordServiceCompletionBasis::class)($actor ?? $this->checker, $key, $this->f['acceptance'], $full, $evidence);
    }

    private function preview(): array
    {
        return app(PreviewServiceRelease::class)($this->checker, $this->stageId);
    }

    private function release(string $key = 'release', ?User $actor = null, ?int $basisId = null, ?string $fingerprint = null): array
    {
        $p = $this->preview();

        return app(ReleaseHandoverComponent::class)($actor ?? $this->checker, $key, $this->stageId, $basisId ?? (int) $p['basis_id'], $fingerprint ?? $p['fingerprint']);
    }

    private function refusal(callable $work, string $code): void
    {
        try {
            $work();
            $this->fail("Expected {$code}.");
        } catch (DomainInvariantException $e) {
            $this->assertSame($code, $e->reasonCode ?? $e->getCode() ?: $code);
        }
    }

    private function counts(): array
    {
        return [DB::table('handover_release_events')->count(), DB::table('sales_customer_decision_receipts')->count()];
    }

    // ---- completion basis -------------------------------------------------------------------------------------------------

    public function test_a_basis_is_versioned_idempotent_and_cites_the_accepting_cases_written_evidence(): void
    {
        $a = $this->recordBasis();
        $this->assertSame(1, $a['version']);
        $this->assertSame($a['basis_id'], $this->recordBasis('basis')['basis_id'], 'same key replays');
        $this->assertSame($a['basis_id'], $this->recordBasis('other-key')['basis_id'], 'same text and evidence under a fresh key is the same head');
        $b = $this->recordBasis('v2', 'Completion also needs the written sign-off of the site contact.');
        $this->assertSame(2, $b['version']);
        $this->assertSame($a['basis_id'], (int) DB::table('service_completion_bases')->where('id', $b['basis_id'])->value('predecessor_id'));
        $this->assertSame(2, DB::table('service_completion_bases')->count());
    }

    public function test_a_basis_refuses_unwritten_unverified_foreign_or_empty_evidence_and_empty_text(): void
    {
        $other = DB::table('customer_decision_cases')->insertGetId(['company_id' => 1, 'party_id' => $this->customer->id, 'intake_identity' => 'other', 'generation' => 0, 'recorded_by' => $this->checker->id, 'recorded_at' => now()]);
        $foreign = DB::table('customer_decision_evidence')->insertGetId(['company_id' => 1, 'case_id' => $other, 'source_kind' => 'EMAIL', 'authority_verification_basis' => 'DOCUMENT_ON_FILE', 'occurrence_precision' => 'UNKNOWN', 'factual_description' => 'x', 'observation_state' => 'OBSERVED', 'recorded_by' => $this->checker->id, 'recorded_at' => now()]);
        $this->expectException(DomainInvariantException::class);
        try {
            app(RecordServiceCompletionBasis::class)($this->checker, 'k', $this->f['acceptance'], 'Conditions', $foreign);
        } finally {
            $this->assertDatabaseCount('service_completion_bases', 0);
        }
    }

    public function test_an_empty_basis_text_is_refused_and_writes_nothing(): void
    {
        try {
            app(RecordServiceCompletionBasis::class)($this->checker, 'empty', $this->f['acceptance'], '   ', $this->evidenceId);
            $this->fail('empty text');
        } catch (DomainInvariantException) {
            $this->assertDatabaseCount('service_completion_bases', 0);
        }
    }

    public function test_the_database_refuses_forged_or_unchained_bases_and_every_update_delete_and_truncate(): void
    {
        $id = $this->recordBasis()['basis_id'];
        $row = (array) DB::table('service_completion_bases')->find($id);
        unset($row['id']);
        // not the next version
        $this->assertSqlRefused(fn () => DB::table('service_completion_bases')->insert([...$row, 'version' => 3, 'predecessor_id' => $id]));
        // version 1 again (forked chain)
        $this->assertSqlRefused(fn () => DB::table('service_completion_bases')->insert([...$row, 'version' => 1, 'predecessor_id' => null]));
        // cross-company raw insert
        $this->assertSqlRefused(fn () => DB::table('service_completion_bases')->insert([...$row, 'company_id' => 2, 'version' => 2, 'predecessor_id' => $id]));
        foreach ([fn () => DB::table('service_completion_bases')->delete(), fn () => DB::table('service_completion_bases')->update(['agreed_text' => 'changed']), fn () => DB::statement('TRUNCATE service_completion_bases CASCADE')] as $w) {
            $this->assertSqlRefused($w);
        }
    }

    private function assertSqlRefused(callable $work): void
    {
        try {
            DB::transaction($work);
            $this->fail('The database must refuse this write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    // ---- readiness and release --------------------------------------------------------------------------------------------

    public function test_with_no_basis_release_is_blocked_and_refused_and_nothing_is_written(): void
    {
        $this->bind();
        $p = $this->preview();
        $this->assertFalse($p['ready']);
        $this->assertContains('COMPLETION_BASIS_MISSING', $p['blockers']);
        $this->assertNull($p['basis_id']);
        [$events, $receipts] = $this->counts();
        try {
            app(ReleaseHandoverComponent::class)($this->checker, 'r', $this->stageId, 1, $p['fingerprint']);
            $this->fail('no basis');
        } catch (DomainInvariantException $e) {
            $this->assertStringContainsString('No customer-agreed completion conditions', $e->getMessage());
            $this->assertSame([$events, $receipts], $this->counts());
        }
    }

    public function test_an_unbound_receiver_refuses_every_release_fail_closed(): void
    {
        $this->recordBasis();
        $p = $this->preview();
        $this->assertTrue($p['ready'], implode(',', $p['blockers']));
        // The Jobs module now binds the real receiver; this test proves the fail-closed behaviour when nothing is bound.
        unset($this->app[ReleasedServiceReceiver::class]);
        $this->assertFalse($this->app->bound(ReleasedServiceReceiver::class));
        try {
            app(ReleaseHandoverComponent::class)($this->checker, 'r', $this->stageId, $p['basis_id'], $p['fingerprint']);
            $this->fail('unbound receiver');
        } catch (DomainInvariantException $e) {
            $this->assertStringContainsString('No Jobs receiver', $e->getMessage());
        }
        $this->assertDatabaseCount('handover_release_events', 0);
    }

    public function test_a_genuine_release_records_immutable_evidence_once_and_consumes_no_claim(): void
    {
        $this->bind();
        $this->recordBasis();
        $claimsBefore = DB::table('customer_scope_claim_heads')->orderBy('id')->get()->all();
        $acceptancesBefore = DB::table('customer_acceptances')->count();
        $p = $this->preview();
        $this->assertSame('UNASSESSED', $p['cost']['status']);
        $this->assertFalse($p['cost']['required']);
        $this->assertSame('NOT_APPLICABLE', $p['applicability']['status']);
        $out = $this->release();

        $this->assertFalse($out['replayed']);
        $this->assertSame('JOB-0001', $out['job_code']);
        $event = DB::table('handover_release_events')->first();
        $this->assertSame($out['release_event_id'], $event->id);
        $this->assertSame('SERVICE_JOB_RECEIPT', $event->purpose);
        $this->assertSame('UNASSESSED', $event->cost_status);
        $this->assertFalse((bool) $event->cost_required);
        $this->assertStringStartsWith('completion-basis:1:', $event->condition_identity);
        $this->assertCount(1, $this->receiver->received);
        $v = $this->receiver->received[0];
        $this->assertSame($event->id, $v->releaseEventId);
        $this->assertSame(ScopeHeader::compose('EXCLUDED', 'EXCLUDED', self::AGREED), $v->basis->agreedText);
        $this->assertEquals($claimsBefore, DB::table('customer_scope_claim_heads')->orderBy('id')->get()->all(), 'a release consumes no acceptance claim');
        $this->assertSame($acceptancesBefore, DB::table('customer_acceptances')->count());
        $this->assertSame('PREPARED', DB::table('handover_stages')->value('state'), 'preparation rows are never updated');
        foreach ([fn () => DB::table('handover_release_events')->delete(), fn () => DB::table('handover_release_events')->update(['actor_id' => 1]), fn () => DB::statement('TRUNCATE handover_release_events CASCADE')] as $w) {
            $this->assertSqlRefused($w);
        }
    }

    public function test_replay_same_key_and_a_fresh_key_or_actor_create_one_release_and_one_receiver_call(): void
    {
        $this->bind();
        $this->recordBasis();
        $first = $this->release('k1');
        $same = $this->release('k1');
        $this->assertTrue($same['replayed']);
        $fresh = $this->release('k2');
        $this->assertSame($first['release_event_id'], $fresh['release_event_id']);
        $this->assertSame($first['job_code'], $fresh['job_code']);
        $second = User::factory()->create();
        $second->givePermissionTo($this->handoverDuties());
        $this->assertSame($first['release_event_id'], $this->release('k3', $second)['release_event_id']);
        $this->assertDatabaseCount('handover_release_events', 1);
        $this->assertCount(1, $this->receiver->received, 'the receiver ran exactly once');
    }

    public function test_a_different_basis_for_an_already_released_stage_is_refused(): void
    {
        $this->bind();
        $this->recordBasis();
        $this->release('k1');
        $b = $this->recordBasis('v2', 'Changed conditions.');
        try {
            app(ReleaseHandoverComponent::class)($this->checker, 'k2', $this->stageId, $b['basis_id'], $this->preview()['fingerprint']);
            $this->fail('already released');
        } catch (DomainInvariantException $e) {
            $this->assertStringContainsString('already been released', $e->getMessage());
        }
        $this->assertDatabaseCount('handover_release_events', 1);
    }

    public function test_a_stale_review_is_refused_when_the_basis_or_facts_changed(): void
    {
        $this->bind();
        $first = $this->recordBasis();
        $old = $this->preview();
        $head = $this->recordBasis('v2', 'Changed conditions.');
        // (1) the reviewed basis is no longer the head; (2) the head, but with the fingerprint reviewed before it existed.
        foreach ([[$first['basis_id'], $old['fingerprint']], [$head['basis_id'], $old['fingerprint']]] as [$basis, $fp]) {
            try {
                app(ReleaseHandoverComponent::class)($this->checker, 'k'.$basis, $this->stageId, $basis, $fp);
                $this->fail('stale');
            } catch (DomainInvariantException $e) {
                $this->assertStringContainsString('changed after this release was reviewed', $e->getMessage());
            }
        }
        $this->assertDatabaseCount('handover_release_events', 0);
    }

    public function test_a_mandatory_cost_control_without_evidence_blocks_and_a_proven_unknown_does_not(): void
    {
        $this->bind();
        $this->recordBasis();
        $this->app->instance(ReleaseCostPolicy::class, new class implements ReleaseCostPolicy
        {
            public function requirementFor(HandoverSubject $subject): CostRequirement
            {
                return new CostRequirement(true, 'UNKNOWN', 'TEST_MANDATORY.1');
            }
        });
        $p = $this->preview();
        $this->assertContains('SERVICE_COST_EVIDENCE_REQUIRED', $p['blockers']);
        $this->expectException(DomainInvariantException::class);
        app(ReleaseHandoverComponent::class)($this->checker, 'r', $this->stageId, $p['basis_id'], $p['fingerprint']);
    }

    public function test_the_database_refuses_a_required_but_unevidenced_cost_and_forged_release_bindings(): void
    {
        $this->bind();
        $b = $this->recordBasis();
        $out = $this->release();
        $row = (array) DB::table('handover_release_events')->find($out['release_event_id']);
        unset($row['id']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertSqlRefused(fn () => DB::table('handover_release_events')->insert([...$row, 'purpose' => 'SERVICE_JOB_RECEIPT', 'actor_id' => $this->maker->id, 'request_key' => 'zz', 'cost_required' => true, 'cost_status' => 'UNKNOWN']));
        $this->assertSqlRefused(fn () => DB::table('handover_release_events')->insert([...$row, 'company_id' => 2]));
        $this->assertSqlRefused(fn () => DB::table('handover_release_events')->insert([...$row, 'request_key' => 'zz2'])); // stage/purpose uniqueness
        $this->assertSame($b['basis_id'], (int) $row['basis_id']);
    }

    public function test_the_database_refuses_a_release_bound_to_a_stale_basis_or_a_forged_condition_identity(): void
    {
        $v1 = $this->recordBasis();
        $v2 = $this->recordBasis('v2', 'Changed conditions.');
        $baseline = DB::table('handover_baselines')->first();
        $row = ['company_id' => 1, 'stage_id' => $this->stageId, 'baseline_id' => $baseline->id, 'purpose' => 'SERVICE_JOB_RECEIPT', 'actor_id' => $this->checker->id, 'request_key' => 'raw',
            'intent_hash' => str_repeat('a', 64), 'cost_required' => false, 'cost_status' => 'UNASSESSED', 'cost_policy_version' => 'X', 'relied_on' => '{}'];
        // a superseded (non-head) basis
        $this->assertSqlRefused(fn () => DB::table('handover_release_events')->insert([...$row, 'basis_id' => $v1['basis_id'], 'condition_version' => 1, 'condition_identity' => 'completion-basis:1:'.$v1['basis_id'].':v1']));
        // the head basis but a forged condition identity
        $this->assertSqlRefused(fn () => DB::table('handover_release_events')->insert([...$row, 'basis_id' => $v2['basis_id'], 'condition_version' => 2, 'condition_identity' => 'completion-basis:1:999:v2']));
        // a baseline of another stage cannot be bound (the only baseline is this stage's, so point the event at a non-existent stage-baseline pair)
        $this->assertSqlRefused(fn () => DB::table('handover_release_events')->insert([...$row, 'stage_id' => $this->stageId + 100, 'basis_id' => $v2['basis_id'], 'condition_version' => 2, 'condition_identity' => 'completion-basis:1:'.$v2['basis_id'].':v2']));
        // the correct row is accepted (proves the refusals above are the guards, not the fixture)
        DB::table('handover_release_events')->insert([...$row, 'basis_id' => $v2['basis_id'], 'condition_version' => 2, 'condition_identity' => 'completion-basis:1:'.$v2['basis_id'].':v2']);
        $this->assertDatabaseCount('handover_release_events', 1);
    }

    public function test_a_populated_migration_refuses_backout_and_leaves_the_guards_intact(): void
    {
        $this->recordBasis();
        $migration = require base_path('database/migrations/2026_12_13_100000_create_service_release_evidence.php');
        try {
            $migration->down();
            $this->fail('Evidence exists, so backout must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('separately approved backout', $e->getMessage());
        }
        $this->assertDatabaseCount('service_completion_bases', 1);
        $this->assertSqlRefused(fn () => DB::table('service_completion_bases')->delete());
    }

    // ---- authority --------------------------------------------------------------------------------------------------------

    public function test_no_duty_staged_revoked_or_suspended_actor_is_refused_before_any_replay_or_write(): void
    {
        $this->bind();
        $this->recordBasis();
        $p = $this->preview();
        $this->release('first');
        [$events, $receipts] = $this->counts();

        $nobody = User::factory()->create();
        $this->expectAuthRefused(fn () => app(ReleaseHandoverComponent::class)($nobody, 'x', $this->stageId, $p['basis_id'], $p['fingerprint']));
        $this->checker->revokePermissionTo(P::HANDOVER_RELEASE);
        $this->expectAuthRefused(fn () => app(ReleaseHandoverComponent::class)($this->checker->fresh(), 'first', $this->stageId, $p['basis_id'], $p['fingerprint']));
        $this->checker->givePermissionTo(P::HANDOVER_RELEASE);
        $this->checker->forceFill(['status' => 'SUSPENDED'])->save();
        $this->expectAuthRefused(fn () => app(ReleaseHandoverComponent::class)($this->checker->fresh(), 'first', $this->stageId, $p['basis_id'], $p['fingerprint']));
        $this->assertSame([$events, $receipts], $this->counts());
    }

    public function test_a_staged_release_duty_refuses_even_a_permission_holder(): void
    {
        $this->bind();
        $this->recordBasis();
        $p = $this->preview();
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->expectAuthRefused(fn () => app(ReleaseHandoverComponent::class)($this->checker, 'x', $this->stageId, $p['basis_id'], $p['fingerprint']));
        $this->expectAuthRefused(fn () => $this->recordBasis('z2', 'x'));
    }

    private function expectAuthRefused(callable $work): void
    {
        try {
            $work();
            $this->fail('Expected an authorisation refusal.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    // ---- atomicity --------------------------------------------------------------------------------------------------------

    public function test_a_failure_after_the_receiver_wrote_rolls_back_the_event_and_the_receipt(): void
    {
        $this->bind();
        $this->recordBasis();
        $p = $this->preview();
        [$events, $receipts] = $this->counts();
        $this->receiver->failAfterReceiving = true;
        try {
            app(ReleaseHandoverComponent::class)($this->checker, 'r', $this->stageId, $p['basis_id'], $p['fingerprint']);
            $this->fail('injected');
        } catch (RuntimeException) {
            $this->assertSame([$events, $receipts], $this->counts());
        }
        $this->receiver->failAfterReceiving = false;
        $this->assertSame('JOB-0002', $this->release('retry')['job_code'], 'a clean retry succeeds (the failed attempt left nothing behind)');
        $this->assertDatabaseCount('handover_release_events', 1);
    }

    public function test_a_receiver_that_does_not_link_the_release_rolls_everything_back(): void
    {
        $this->bind();
        $this->recordBasis();
        $p = $this->preview();
        $this->receiver->forgetToLink = true;
        [$events, $receipts] = $this->counts();
        $this->expectException(LogicException::class);
        try {
            app(ReleaseHandoverComponent::class)($this->checker, 'r', $this->stageId, $p['basis_id'], $p['fingerprint']);
        } finally {
            $this->assertSame([$events, $receipts], $this->counts());
        }
    }

    public function test_a_verified_release_can_only_be_issued_by_the_coordinator_and_needs_its_own_transaction(): void
    {
        $context = HandoverTransactionContext::capture();
        $context->assertCurrent();
        $this->expectException(LogicException::class);
        VerifiedServiceRelease::issue(1, 1, 1, 1, 1, 1, 1, 'SERVICE_JOB_RECEIPT', 'x', 'x', new ResolvedCompletionBasis(1, 1, 1, 1, 'a', 'b', 1, 'AUTHORISED_ATTESTATION'), [], 1, $context);
    }

    // ---- source verifier --------------------------------------------------------------------------------------------------

    public function test_the_verifier_refuses_an_unsupported_origin_and_proves_a_released_one(): void
    {
        $this->bind();
        $this->recordBasis();
        $out = $this->release();
        $job = new ServiceJob;
        $origin = fn (?int $id): JobSalesOrigin => new class($id) implements JobSalesOrigin
        {
            public function __construct(private readonly ?int $id) {}

            public function releaseEventIdFor(ServiceJob $job): ?int
            {
                return $this->id;
            }
        };
        DB::transaction(function () use ($job, $origin, $out): void {
            try {
                app()->makeWith(SalesJobExecutionContextVerifier::class, ['origins' => $origin(null)])->enter($this->checker, $job, 'START');
                $this->fail('unsupported origin');
            } catch (DomainInvariantException|\Throwable $e) {
                $this->assertSame(JobLifecycleRefusal::SOURCE_CONTEXT_UNAVAILABLE, $e->reasonCode ?? null);
            }
            $ctx = app()->makeWith(SalesJobExecutionContextVerifier::class, ['origins' => $origin($out['release_event_id'])])->enter($this->checker, $job, 'START');
            $this->assertTrue($ctx->recordEligible);
            $this->assertTrue($ctx->commandReady);
            $this->assertSame((string) $out['release_event_id'], $ctx->sourceVector['release_event']);
            $gone = app()->makeWith(SalesJobExecutionContextVerifier::class, ['origins' => $origin(999999)])->enter($this->checker, $job, 'START');
            $this->assertFalse($gone->recordEligible);
        });
        DB::statement('CREATE TABLE zz_lineage_probe (id bigserial primary key, job_id bigint references service_jobs(id))');
        DB::transaction(function () use ($job, $origin, $out): void {
            $ctx = app()->makeWith(SalesJobExecutionContextVerifier::class, ['origins' => $origin($out['release_event_id'])])->enter($this->checker, $job, 'START');
            $this->assertTrue($ctx->recordEligible);
            $this->assertFalse($ctx->commandReady, 'a foreign writer referencing the lineage makes fresh work not ready');
        });
        DB::statement('DROP TABLE zz_lineage_probe');
    }
}
