<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Sales\Actions\PrepareHandoverPlan;
use App\Modules\Sales\Actions\PreviewHandover;
use App\Modules\Sales\Actions\PreviewServiceRelease;
use App\Modules\Sales\Actions\RecordServiceCompletionBasis;
use App\Modules\Sales\Actions\ReleaseHandoverComponent;
use App\Modules\Sales\Support\Handover\Release\JobSalesOrigin;
use App\Modules\Sales\Support\Handover\Release\ReleasedServiceReceiver;
use App\Modules\Sales\Support\Handover\Release\SalesJobExecutionContextVerifier;
use App\Modules\Sales\Support\Handover\Release\SourceApplicabilityProof;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Support\JobSource\FamilyAssessment;
use App\Support\JobSource\JobSourceFamilies;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsHandoverFixture;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\Support\Sales\FakeReleasedServiceReceiver;
use Tests\Support\Sales\ScopedReleaseFixtures;
use Tests\TestCase;

/**
 * #122 — corrected source applicability (customer-agreed scope + every family authority + the FK catalogue) and completion-basis agreement integrity. SERVICE_ITEM, an
 * empty query, the absence of foreign keys and an issued-line description alone can never yield NOT_APPLICABLE.
 */
class ReleaseApplicabilityTest extends TestCase
{
    use BuildsHandoverFixture;
    use BuildsIssuableQuotations;
    use RefreshDatabase;
    use ScopedReleaseFixtures;

    private array $f;

    private int $consentEvidence;

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
        $this->consentEvidence = (int) DB::table('customer_decision_evidence')->where('case_id', $this->f['case'])->value('id');
        $token = app(PreviewHandover::class)($this->checker, $this->f['acceptance'], $this->f['inputs'])['preview_token'];
        app(PrepareHandoverPlan::class)($this->checker, 'prepare', $this->f['acceptance'], $this->f['inputs'], $token);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->stageId = (int) DB::table('handover_stages')->value('id');
        $this->receiver = new FakeReleasedServiceReceiver;
        $this->app->instance(ReleasedServiceReceiver::class, $this->receiver);
        $this->registerSourceFamilies();
    }

    private function preview(): array
    {
        return app(PreviewServiceRelease::class)($this->checker, $this->stageId);
    }

    private function release(array $p, string $key = 'release'): array
    {
        return app(ReleaseHandoverComponent::class)($this->checker, $key, $this->stageId, (int) $p['basis_id'], $p['fingerprint']);
    }

    private function issuedText(): string
    {
        return trim((string) DB::table('quotation_issued_lines')->value('description'));
    }

    /** @return array{basis_id:int,version:int,case_id:int,replayed:bool} the lightweight default: the issued line verbatim on the acceptance's own evidence */
    private function issuedLineBasis(string $key = 'issued'): array
    {
        return app(RecordServiceCompletionBasis::class)($this->checker, $key, $this->f['acceptance'], null, $this->consentEvidence);
    }

    private function refused(callable $work): void
    {
        try {
            $work();
            $this->fail('Expected a refusal.');
        } catch (DomainInvariantException) {
            $this->addToAssertionCount(1);
        }
    }

    private function sqlRefused(callable $work): void
    {
        try {
            DB::transaction($work);
            $this->fail('The database must refuse this write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function origin(int $eventId): JobSalesOrigin
    {
        return new class($eventId) implements JobSalesOrigin
        {
            public function __construct(private readonly int $id) {}

            public function releaseEventIdFor(ServiceJob $job): ?int
            {
                return $this->id;
            }
        };
    }

    private function verify(int $eventId): object
    {
        $ctx = null;
        DB::transaction(function () use ($eventId, &$ctx): void {
            $ctx = app()->makeWith(SalesJobExecutionContextVerifier::class, ['origins' => $this->origin($eventId)])->enter($this->checker, new ServiceJob, 'START');
        });

        return $ctx;
    }

    // ---- the lightweight default and what it cannot prove -----------------------------------------------------------------

    public function test_the_issued_line_default_works_without_extra_steps_but_leaves_applicability_unknown_and_blocks_release(): void
    {
        $b = $this->issuedLineBasis();
        $row = DB::table('service_completion_bases')->find($b['basis_id']);
        $this->assertSame('ISSUED_LINE', $row->terms_source);
        $this->assertSame($this->issuedText(), $row->agreed_text);
        $this->assertSame([$this->consentEvidence, 'UNSTATED', 'UNSTATED'], [(int) $row->evidence_id, $row->materials_scope, $row->subcontract_scope]);
        $this->assertSame($b['basis_id'], $this->issuedLineBasis('again')['basis_id'], 'a fresh key with the same intent is the same head');

        $p = $this->preview();
        $this->assertFalse($p['ready']);
        $this->assertSame('UNKNOWN', $p['applicability']['status']);
        $this->assertContains('SOURCE_APPLICABILITY_UNPROVEN', $p['blockers']);
        $this->assertContains('Sales', array_column($p['next_actions'], 'owner'));
        $this->refused(fn () => $this->release($p));
        $this->assertDatabaseCount('handover_release_events', 0);
        $this->assertCount(0, $this->receiver->received);
    }

    public function test_service_item_empty_queries_and_missing_foreign_keys_alone_are_never_not_applicable(): void
    {
        // The fixture IS a SERVICE_ITEM line with no foreign key anywhere and no stock query result; with an unregistered family and no scope evidence it is UNKNOWN.
        $this->app->instance(JobSourceFamilies::class, new JobSourceFamilies);
        $this->assertSame('UNKNOWN', app(SourceApplicabilityProof::class)->evaluate(null)['status']);
        $this->recordScopedBasis($this->checker, 'scoped', $this->f['acceptance'], $this->f['case']);
        $p = $this->preview();
        $this->assertSame('UNKNOWN', $p['applicability']['status'], 'scope excluded but no source owner has answered');
        $this->assertSame(['UNKNOWN', 'UNKNOWN'], array_column($p['applicability']['families'], 'status'));
        $this->assertContains('Inventory', array_column($p['next_actions'], 'owner'));
        $this->assertContains('Purchasing', array_column($p['next_actions'], 'owner'));
    }

    public function test_every_family_must_answer_not_applicable_and_scope_must_exclude_both(): void
    {
        $this->recordScopedBasis($this->checker, 's1', $this->f['acceptance'], $this->f['case']);
        $this->registerSourceFamilies(FamilyAssessment::NOT_APPLICABLE, FamilyAssessment::UNKNOWN);
        $this->assertSame('UNKNOWN', $this->preview()['applicability']['status']);
        $this->registerSourceFamilies(FamilyAssessment::NOT_APPLICABLE, null);
        $this->assertSame('UNKNOWN', $this->preview()['applicability']['status'], 'an unregistered required family is UNKNOWN, never a no');
        $this->registerSourceFamilies(FamilyAssessment::APPLICABLE, FamilyAssessment::NOT_APPLICABLE);
        $p = $this->preview();
        $this->assertSame('APPLICABLE', $p['applicability']['status']);
        $this->assertContains('SOURCE_OBLIGATION_UNSUPPORTED', $p['blockers']);
        // A scope that leaves one obligation unstated is UNKNOWN even with every family clean.
        $this->registerSourceFamilies();
        $this->recordScopedBasis($this->checker, 's2', $this->f['acceptance'], $this->f['case'], 'Materials are excluded; subcontractors are not mentioned.', 'EXCLUDED', 'UNSTATED');
        $this->assertSame('UNKNOWN', $this->preview()['applicability']['status']);
        // A scope that REQUIRES materials is APPLICABLE however clean the families are: the first pilot refuses.
        $this->recordScopedBasis($this->checker, 's3', $this->f['acceptance'], $this->f['case'], 'The service includes supplying the pipes.', 'REQUIRED', 'EXCLUDED');
        $p = $this->preview();
        $this->assertSame('APPLICABLE', $p['applicability']['status']);
        $this->assertContains('SOURCE_OBLIGATION_UNSUPPORTED', $p['blockers']);
        $this->refused(fn () => $this->release($p));
        $this->assertDatabaseCount('handover_release_events', 0);
    }

    public function test_the_foreign_key_catalogue_still_blocks_even_with_scope_and_every_family_clean(): void
    {
        $this->recordScopedBasis($this->checker, 'scoped', $this->f['acceptance'], $this->f['case']);
        DB::statement('CREATE TABLE zz_lineage_probe (id bigserial primary key, job_id bigint references service_jobs(id))');
        try {
            $p = $this->preview();
            $this->assertSame('UNKNOWN', $p['applicability']['status']);
            $this->assertSame(['zz_lineage_probe'], $p['applicability']['referencing']);
        } finally {
            DB::statement('DROP TABLE zz_lineage_probe');
        }
    }

    public function test_all_three_proofs_release_and_the_pin_is_recorded(): void
    {
        $this->recordScopedBasis($this->checker, 'scoped', $this->f['acceptance'], $this->f['case']);
        $p = $this->preview();
        $this->assertTrue($p['ready'], implode(',', $p['blockers']));
        $this->assertSame('NOT_APPLICABLE', $p['applicability']['status']);
        $this->assertSame(['TEST_NO_JOB_BINDING.1', 'TEST_NO_JOB_BINDING.1'], array_column($p['applicability']['families'], 'contract'));
        $out = $this->release($p);
        $event = DB::table('handover_release_events')->find($out['release_event_id']);
        $this->assertSame($p['applicability']['pin'], json_decode($event->relied_on, true)['applicability']['pin']);
        $this->assertSame(SourceApplicabilityProof::VERSION, json_decode($event->relied_on, true)['applicability']['version']);
    }

    public function test_a_changed_family_contract_or_basis_invalidates_the_review_and_later_fresh_work(): void
    {
        $this->recordScopedBasis($this->checker, 'scoped', $this->f['acceptance'], $this->f['case']);
        $stale = $this->preview();
        $this->registerSourceFamilies(FamilyAssessment::NOT_APPLICABLE, FamilyAssessment::NOT_APPLICABLE, 'TEST_NO_JOB_BINDING.2');
        $this->refused(fn () => $this->release($stale));
        $this->assertDatabaseCount('handover_release_events', 0);

        $fresh = $this->preview();
        $this->assertNotSame($stale['fingerprint'], $fresh['fingerprint']);
        $out = $this->release($fresh);
        $ctx = $this->verify($out['release_event_id']);
        $this->assertTrue($ctx->recordEligible);
        $this->assertTrue($ctx->commandReady, 'start is ready while the pinned proof still holds');
        $this->assertSame($fresh['applicability']['pin'], $ctx->sourceVector['applicability_pin']);

        $this->registerSourceFamilies(FamilyAssessment::NOT_APPLICABLE, FamilyAssessment::NOT_APPLICABLE, 'TEST_NO_JOB_BINDING.3');
        $this->assertFalse($this->verify($out['release_event_id'])->commandReady, 'a changed family contract version makes fresh work not ready');
        $this->registerSourceFamilies(FamilyAssessment::NOT_APPLICABLE, FamilyAssessment::NOT_APPLICABLE, 'TEST_NO_JOB_BINDING.2');
        $this->assertTrue($this->verify($out['release_event_id'])->commandReady);
        $this->registerSourceFamilies(FamilyAssessment::APPLICABLE, FamilyAssessment::NOT_APPLICABLE, 'TEST_NO_JOB_BINDING.2');
        $this->assertFalse($this->verify($out['release_event_id'])->commandReady, 'a family that now reports a supported binding refuses fresh work');
        $this->registerSourceFamilies(FamilyAssessment::NOT_APPLICABLE, FamilyAssessment::NOT_APPLICABLE, 'TEST_NO_JOB_BINDING.2');
        $this->recordScopedBasis($this->checker, 'v2', $this->f['acceptance'], $this->f['case'], 'Changed conditions: also needs written sign-off.');
        $this->assertFalse($this->verify($out['release_event_id'])->commandReady, 'a newer completion basis head changes the proof pin');
    }

    // ---- completion-basis agreement integrity -----------------------------------------------------------------------------

    public function test_new_conditions_cannot_ride_on_the_acceptances_own_evidence(): void
    {
        $record = fn (?string $text, int $evidence, string $m = 'UNSTATED', string $s = 'UNSTATED') => fn () => app(RecordServiceCompletionBasis::class)($this->checker, 'k'.md5((string) $text.$m.$s), $this->f['acceptance'], $text, $evidence, $m, $s);
        $this->refused($record('We also promise a lifetime warranty.', $this->consentEvidence));
        $this->refused($record(null, $this->consentEvidence, 'EXCLUDED', 'EXCLUDED'));
        $this->refused($record($this->issuedText(), $this->consentEvidence, 'EXCLUDED', 'UNSTATED'));
        $this->assertDatabaseCount('service_completion_bases', 0);
    }

    public function test_dedicated_evidence_must_be_written_verified_matching_and_of_the_accepting_case(): void
    {
        $record = fn (string $text, int $evidence) => fn () => app(RecordServiceCompletionBasis::class)($this->checker, 'k'.$evidence, $this->f['acceptance'], $text, $evidence, 'EXCLUDED', 'EXCLUDED');
        $this->refused($record('Verbal agreement text.', $this->appendAgreementEvidence($this->f['case'], $this->checker->id, 'Verbal agreement text.', 'VERBAL')));
        $this->refused($record('Unverified text.', $this->appendAgreementEvidence($this->f['case'], $this->checker->id, 'Unverified text.', 'EMAIL', 'UNVERIFIED')));
        $this->refused($record('Stated only text.', $this->appendAgreementEvidence($this->f['case'], $this->checker->id, 'Stated only text.', 'EMAIL', 'STATED_BY_CUSTOMER')));
        $this->refused($record('The text the employee wrote.', $this->appendAgreementEvidence($this->f['case'], $this->checker->id, 'A different text the customer evidenced.')));
        $other = DB::table('customer_decision_cases')->insertGetId(['company_id' => 1, 'party_id' => $this->customer->id, 'intake_identity' => 'other', 'generation' => 0, 'recorded_by' => $this->checker->id, 'recorded_at' => now()]);
        $this->refused($record('Foreign case text.', $this->appendAgreementEvidence($other, $this->checker->id, 'Foreign case text.')));
        $this->assertDatabaseCount('service_completion_bases', 0);

        $good = $this->appendAgreementEvidence($this->f['case'], $this->checker->id);
        $ok = app(RecordServiceCompletionBasis::class)($this->checker, 'ok', $this->f['acceptance'], self::AGREED, $good, 'EXCLUDED', 'EXCLUDED');
        $this->assertSame('EVIDENCE_TEXT', DB::table('service_completion_bases')->find($ok['basis_id'])->terms_source);
        $replay = app(RecordServiceCompletionBasis::class)($this->checker, 'ok', $this->f['acceptance'], self::AGREED, $good, 'EXCLUDED', 'EXCLUDED');
        $this->assertTrue($replay['replayed']);
        $this->assertSame($ok['basis_id'], $replay['basis_id'], 'the same key replays the same outcome');
    }

    public function test_one_evidence_row_cannot_back_two_bases_and_the_chain_versions_forward(): void
    {
        $evidence = $this->appendAgreementEvidence($this->f['case'], $this->checker->id);
        $first = app(RecordServiceCompletionBasis::class)($this->checker, 'a', $this->f['acceptance'], self::AGREED, $evidence, 'EXCLUDED', 'EXCLUDED');
        $same = app(RecordServiceCompletionBasis::class)($this->checker, 'b', $this->f['acceptance'], self::AGREED, $evidence, 'EXCLUDED', 'EXCLUDED');
        $this->assertSame($first['basis_id'], $same['basis_id'], 'the identical intent is the same head');
        $this->refused(fn () => app(RecordServiceCompletionBasis::class)($this->checker, 'c', $this->f['acceptance'], self::AGREED, $evidence, 'REQUIRED', 'EXCLUDED'));
        $v2 = $this->recordScopedBasis($this->checker, 'v2', $this->f['acceptance'], $this->f['case'], 'Second agreed wording.');
        $this->assertSame(2, $v2['version']);
        $this->assertSame($first['basis_id'], (int) DB::table('service_completion_bases')->where('id', $v2['basis_id'])->value('predecessor_id'));
        $this->assertSame(2, DB::table('service_completion_bases')->count());
    }

    public function test_the_database_refuses_forged_agreement_rows_whatever_the_application_does(): void
    {
        $good = $this->recordScopedBasis($this->checker, 'good', $this->f['acceptance'], $this->f['case']);
        $row = (array) DB::table('service_completion_bases')->find($good['basis_id']);
        unset($row['id']);
        $next = [...$row, 'version' => 2, 'predecessor_id' => $good['basis_id']];
        $issued = $this->issuedText();
        // EXCLUDED declared on the issued-line path, text other than the line, wrong evidence for the path.
        $this->sqlRefused(fn () => DB::table('service_completion_bases')->insert([...$next, 'terms_source' => 'ISSUED_LINE', 'evidence_id' => $this->consentEvidence, 'agreed_text' => $issued]));
        $this->sqlRefused(fn () => DB::table('service_completion_bases')->insert([...$next, 'terms_source' => 'ISSUED_LINE', 'evidence_id' => $this->consentEvidence, 'agreed_text' => 'Invented obligation', 'materials_scope' => 'UNSTATED', 'subcontract_scope' => 'UNSTATED']));
        $this->sqlRefused(fn () => DB::table('service_completion_bases')->insert([...$next, 'terms_source' => 'ISSUED_LINE', 'evidence_id' => $good['basis_id'] ? $row['evidence_id'] : 0, 'agreed_text' => $issued, 'materials_scope' => 'UNSTATED', 'subcontract_scope' => 'UNSTATED']));
        // EVIDENCE_TEXT riding on the acceptance's own consent evidence, or on text its evidence does not say.
        $this->sqlRefused(fn () => DB::table('service_completion_bases')->insert([...$next, 'terms_source' => 'EVIDENCE_TEXT', 'evidence_id' => $this->consentEvidence, 'agreed_text' => $issued]));
        $other = $this->appendAgreementEvidence($this->f['case'], $this->checker->id, 'What the customer evidenced.');
        $this->sqlRefused(fn () => DB::table('service_completion_bases')->insert([...$next, 'terms_source' => 'EVIDENCE_TEXT', 'evidence_id' => $other, 'agreed_text' => 'What the employee invented.']));
        // The same evidence twice for one line (unique), a scope outside the vocabulary, a scope on the issued-line path (CHECK).
        $this->sqlRefused(fn () => DB::table('service_completion_bases')->insert([...$next, 'evidence_id' => $row['evidence_id']]));
        $this->sqlRefused(fn () => DB::table('service_completion_bases')->insert([...$next, 'evidence_id' => $other, 'agreed_text' => 'What the customer evidenced.', 'materials_scope' => 'MAYBE']));
        $this->sqlRefused(fn () => DB::table('service_completion_bases')->insert([...$next, 'terms_source' => 'ISSUED_LINE', 'evidence_id' => $this->consentEvidence, 'agreed_text' => $issued, 'materials_scope' => 'EXCLUDED']));
        // The one honest next version is accepted.
        DB::table('service_completion_bases')->insert([...$next, 'evidence_id' => $other, 'agreed_text' => 'What the customer evidenced.', 'intent_digest' => str_repeat('b', 64)]);
        $this->assertSame(2, DB::table('service_completion_bases')->count());
    }

    public function test_evidence_recorded_before_the_acceptances_consent_is_refused_by_the_database(): void
    {
        $good = $this->recordScopedBasis($this->checker, 'good', $this->f['acceptance'], $this->f['case']);
        $row = (array) DB::table('service_completion_bases')->find($good['basis_id']);
        unset($row['id']);
        try {
            DB::statement('SET LOCAL session_replication_role = replica');
        } catch (QueryException) {
            $this->markTestSkipped('The test database role cannot bypass triggers to back-date evidence.');
        }
        $consentAt = DB::table('customer_decision_evidence')->where('id', $this->consentEvidence)->value('recorded_at');
        $old = DB::table('customer_decision_evidence')->insertGetId(['company_id' => 1, 'case_id' => $this->f['case'], 'source_kind' => 'EMAIL', 'authority_verification_basis' => 'DOCUMENT_ON_FILE',
            'customer_representative_snapshot' => 'Synthetic buyer', 'occurrence_precision' => 'UNKNOWN', 'factual_description' => 'Older general text.', 'observation_state' => 'OBSERVED',
            'recorded_by' => $this->checker->id, 'recorded_at' => DB::raw("'{$consentAt}'::timestamptz - interval '1 hour'")]);
        DB::statement('SET LOCAL session_replication_role = origin');
        $this->sqlRefused(fn () => DB::table('service_completion_bases')->insert([...$row, 'version' => 2, 'predecessor_id' => $good['basis_id'], 'evidence_id' => $old, 'agreed_text' => 'Older general text.']));
        $this->refused(fn () => app(RecordServiceCompletionBasis::class)($this->checker, 'older', $this->f['acceptance'], 'Older general text.', $old, 'EXCLUDED', 'EXCLUDED'));
    }
}
