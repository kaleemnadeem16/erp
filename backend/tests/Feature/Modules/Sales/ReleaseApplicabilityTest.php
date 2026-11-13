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
use App\Modules\Sales\Support\Handover\Release\ScopeHeader;
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
        $record = fn (?string $text, int $evidence) => fn () => app(RecordServiceCompletionBasis::class)($this->checker, 'k'.md5((string) $text), $this->f['acceptance'], $text, $evidence);
        $this->refused($record('We also promise a lifetime warranty.', $this->consentEvidence));
        $this->refused($record($this->scopeDeclaration($this->f['acceptance']), $this->consentEvidence));
        $this->assertDatabaseCount('service_completion_bases', 0);
    }

    public function test_dedicated_evidence_must_be_written_verified_matching_and_of_the_accepting_case(): void
    {
        $acc = $this->f['acceptance'];
        $text = $this->scopeDeclaration($acc);
        $record = fn (string $agreed, int $evidence) => fn () => app(RecordServiceCompletionBasis::class)($this->checker, 'k'.$evidence, $acc, $agreed, $evidence);
        $this->refused($record($text, $this->appendAgreementEvidence($this->f['case'], $this->checker->id, $text, 'VERBAL')));
        $this->refused($record($text, $this->appendAgreementEvidence($this->f['case'], $this->checker->id, $text, 'EMAIL', 'UNVERIFIED')));
        $this->refused($record($text, $this->appendAgreementEvidence($this->f['case'], $this->checker->id, $text, 'EMAIL', 'STATED_BY_CUSTOMER')));
        // The employee's text differs from what the customer-evidenced record says (another classification).
        $this->refused($record($text, $this->appendAgreementEvidence($this->f['case'], $this->checker->id, $this->scopeDeclaration($acc, 'REQUIRED', 'EXCLUDED'))));
        $other = DB::table('customer_decision_cases')->insertGetId(['company_id' => 1, 'party_id' => $this->customer->id, 'intake_identity' => 'other', 'generation' => 0, 'recorded_by' => $this->checker->id, 'recorded_at' => now()]);
        $this->refused($record($text, $this->appendAgreementEvidence($other, $this->checker->id, $text)));
        $this->assertDatabaseCount('service_completion_bases', 0);

        $good = $this->appendAgreementEvidence($this->f['case'], $this->checker->id, $text);
        $ok = app(RecordServiceCompletionBasis::class)($this->checker, 'ok', $acc, $text, $good);
        $this->assertSame('EVIDENCE_TEXT', DB::table('service_completion_bases')->find($ok['basis_id'])->terms_source);
        $replay = app(RecordServiceCompletionBasis::class)($this->checker, 'ok', $acc, $text, $good);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($ok['basis_id'], $replay['basis_id'], 'the same key replays the same outcome');
    }

    public function test_one_evidence_row_cannot_back_two_bases_and_the_chain_versions_forward(): void
    {
        $text = $this->scopeDeclaration($this->f['acceptance']);
        $evidence = $this->appendAgreementEvidence($this->f['case'], $this->checker->id, $text);
        $first = app(RecordServiceCompletionBasis::class)($this->checker, 'a', $this->f['acceptance'], $text, $evidence);
        $same = app(RecordServiceCompletionBasis::class)($this->checker, 'b', $this->f['acceptance'], $text, $evidence);
        $this->assertSame($first['basis_id'], $same['basis_id'], 'the identical intent is the same head');
        // The evidence fixes the text AND the classification: the same evidence cannot be re-cited with a different classification.
        $this->refused(fn () => app(RecordServiceCompletionBasis::class)($this->checker, 'c', $this->f['acceptance'], $this->scopeDeclaration($this->f['acceptance'], 'REQUIRED', 'EXCLUDED'), $evidence));
        $v2 = $this->recordScopedBasis($this->checker, 'v2', $this->f['acceptance'], $this->f['case'], 'Second agreement evidence.');
        $this->assertSame(2, $v2['version']);
        $this->assertSame($first['basis_id'], (int) DB::table('service_completion_bases')->where('id', $v2['basis_id'])->value('predecessor_id'));
        $this->assertSame(2, DB::table('service_completion_bases')->count());
    }

    // ---- classification integrity: the scope is DERIVED from the customer-evidenced header, never chosen (review P1) ---------------------------------------

    public function test_the_scope_is_derived_from_the_declaration_and_every_ambiguous_agreement_is_refused(): void
    {
        foreach (['EXCLUDED' => 'REQUIRED', 'REQUIRED' => 'UNSTATED', 'UNSTATED' => 'EXCLUDED'] as $m => $s) {
            $out = $this->recordScopedBasis($this->checker, "d-{$m}", $this->f['acceptance'], $this->f['case'], "Evidence for {$m}/{$s}.", $m, $s);
            $row = DB::table('service_completion_bases')->find($out['basis_id']);
            $this->assertSame([$m, $s], [$row->materials_scope, $row->subcontract_scope], 'stored scope equals the evidenced declaration');
        }
        $acc = $this->f['acceptance'];
        $lineId = (int) DB::table('customer_acceptance_lines')->where('acceptance_id', $acc)->value('issued_line_id');
        $digest = ScopeHeader::digest((string) DB::table('quotation_issued_lines')->where('id', $lineId)->value('description'));
        $valid = $this->scopeDeclaration($acc);
        $bad = [
            // The incorrect case from the owner review: the header says EXCLUDED, the free text says materials are required.
            'free text contradicting the header' => $valid."\nMaterials required for the work",
            'free text on its own line' => "MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nMaterials required for the work\nLINE: {$lineId}\nDELIVERABLE_SHA256: {$digest}",
            'trailing newline' => $valid."\n",
            'only two lines (the previous format)' => "MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED",
            'two lines plus free text (the previous format)' => "MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nMaterials required for the work",
            'missing digest' => "MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nLINE: {$lineId}",
            'missing line' => "MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nDELIVERABLE_SHA256: {$digest}",
            'reordered' => "SUBCONTRACTORS: EXCLUDED\nMATERIALS: EXCLUDED\nLINE: {$lineId}\nDELIVERABLE_SHA256: {$digest}",
            'line and digest swapped' => "MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nDELIVERABLE_SHA256: {$digest}\nLINE: {$lineId}",
            'duplicated materials' => $valid."\nMATERIALS: REQUIRED",
            'duplicated digest' => $valid."\nDELIVERABLE_SHA256: {$digest}",
            'wrong line id' => "MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nLINE: ".($lineId + 1)."\nDELIVERABLE_SHA256: {$digest}",
            'wrong digest' => "MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nLINE: {$lineId}\nDELIVERABLE_SHA256: ".str_repeat('0', 64),
            'digest of another text' => "MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nLINE: {$lineId}\nDELIVERABLE_SHA256: ".ScopeHeader::digest('Some other deliverable text'),
            'uppercase digest' => "MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nLINE: {$lineId}\nDELIVERABLE_SHA256: ".strtoupper($digest),
            'unknown value' => "MATERIALS: NONE\nSUBCONTRACTORS: EXCLUDED\nLINE: {$lineId}\nDELIVERABLE_SHA256: {$digest}",
            'lowercase keyword' => "materials: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nLINE: {$lineId}\nDELIVERABLE_SHA256: {$digest}",
            'extra spaces' => "MATERIALS:  EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nLINE: {$lineId}\nDELIVERABLE_SHA256: {$digest}",
            'CRLF' => str_replace("\n", "\r\n", $valid),
            'preamble before the declaration' => "Preamble.\n".$valid,
            'keyword line inside conditions text' => "The customer agrees to the following.\nMATERIALS: EXCLUDED",
        ];
        $count = DB::table('service_completion_bases')->count();
        foreach ($bad as $label => $text) {
            $evidence = $this->appendAgreementEvidence($this->f['case'], $this->checker->id, $text);
            $this->refused(fn () => app(RecordServiceCompletionBasis::class)($this->checker, 'bad-'.md5($label), $acc, $text, $evidence), $label);
        }
        $this->assertSame($count, DB::table('service_completion_bases')->count(), 'no ambiguous agreement wrote anything');
    }

    public function test_a_required_declaration_is_applicable_and_a_conditions_only_head_resets_the_scope_to_unknown(): void
    {
        $this->recordScopedBasis($this->checker, 'excl', $this->f['acceptance'], $this->f['case']);
        $this->assertSame('NOT_APPLICABLE', $this->preview()['applicability']['status']);
        // Re-stating conditions as free text (no declaration) makes the HEAD declare no scope: UNKNOWN, release refuses until the declaration is recorded again.
        $conditions = 'Completion also needs the written sign-off of the site contact.';
        $ev = $this->appendAgreementEvidence($this->f['case'], $this->checker->id, $conditions);
        $out = app(RecordServiceCompletionBasis::class)($this->checker, 'cond', $this->f['acceptance'], $conditions, $ev);
        $row = DB::table('service_completion_bases')->find($out['basis_id']);
        $this->assertSame(['EVIDENCE_TEXT', 'UNSTATED', 'UNSTATED'], [$row->terms_source, $row->materials_scope, $row->subcontract_scope]);
        $p = $this->preview();
        $this->assertSame('UNKNOWN', $p['applicability']['status']);
        $this->assertContains('SOURCE_APPLICABILITY_UNPROVEN', $p['blockers']);
        $this->refused(fn () => $this->release($p));
        $this->assertDatabaseCount('handover_release_events', 0);
        // Declaring again (new evidence, new version) restores the proof.
        $this->recordScopedBasis($this->checker, 'again', $this->f['acceptance'], $this->f['case'], 'Declared again.');
        $this->assertSame('NOT_APPLICABLE', $this->preview()['applicability']['status']);
        // A REQUIRED declaration, with every family clean, is APPLICABLE and the pilot refuses.
        $this->recordScopedBasis($this->checker, 'req', $this->f['acceptance'], $this->f['case'], 'Supply the pipes.', 'REQUIRED', 'EXCLUDED');
        $p = $this->preview();
        $this->assertSame('APPLICABLE', $p['applicability']['status']);
        $this->assertContains('SOURCE_OBLIGATION_UNSUPPORTED', $p['blockers']);
    }

    public function test_the_resolver_ignores_a_stored_scope_that_is_not_a_verified_declaration(): void
    {
        // Defence in depth: a legacy-format row (or any row whose text is not a declaration for THIS line with its current digest) counts as UNSTATED => UNKNOWN.
        $good = $this->recordScopedBasis($this->checker, 'good', $this->f['acceptance'], $this->f['case']);
        $this->assertSame('NOT_APPLICABLE', $this->preview()['applicability']['status']);
        try {
            DB::statement('SET LOCAL session_replication_role = replica');
        } catch (QueryException) {
            $this->markTestSkipped('The test database role cannot bypass triggers to plant a legacy-format row.');
        }
        DB::table('service_completion_bases')->where('id', $good['basis_id'])->update(['agreed_text' => "MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nMaterials required for the work"]);
        DB::statement('SET LOCAL session_replication_role = origin');
        $p = $this->preview();
        $this->assertSame('UNKNOWN', $p['applicability']['status']);
        $this->assertSame('UNSTATED', $p['applicability']['scope']['materials']);
    }

    public function test_an_issued_line_description_that_looks_like_a_header_still_states_no_scope(): void
    {
        try {
            DB::statement('SET LOCAL session_replication_role = replica');
        } catch (QueryException) {
            $this->markTestSkipped('The test database role cannot bypass triggers to edit the issued description.');
        }
        $lineId = (int) DB::table('customer_acceptance_lines')->where('acceptance_id', $this->f['acceptance'])->value('issued_line_id');
        $headerLike = ScopeHeader::compose('EXCLUDED', 'EXCLUDED', $lineId, 'Fixed service.');
        DB::table('quotation_issued_lines')->where('id', $lineId)->update(['description' => $headerLike]);
        DB::statement('SET LOCAL session_replication_role = origin');
        $out = app(RecordServiceCompletionBasis::class)($this->checker, 'hl', $this->f['acceptance'], null, $this->consentEvidence);
        $row = DB::table('service_completion_bases')->find($out['basis_id']);
        $this->assertSame(['ISSUED_LINE', 'UNSTATED', 'UNSTATED'], [$row->terms_source, $row->materials_scope, $row->subcontract_scope], 'only dedicated evidence can state a scope');
    }

    public function test_the_database_derives_the_scope_from_the_declaration_whatever_the_application_does(): void
    {
        $good = $this->recordScopedBasis($this->checker, 'good', $this->f['acceptance'], $this->f['case']);
        $row = (array) DB::table('service_completion_bases')->find($good['basis_id']);
        unset($row['id']);
        $next = [...$row, 'version' => 2, 'predecessor_id' => $good['basis_id'], 'intent_digest' => str_repeat('c', 64)];
        $insert = function (string $text, string $m, string $s) use (&$next): \Closure {
            $evidence = $this->appendAgreementEvidence($this->f['case'], $this->checker->id, $text);
            $base = $next;

            return fn () => DB::table('service_completion_bases')->insert([...$base, 'evidence_id' => $evidence, 'agreed_text' => $text, 'materials_scope' => $m, 'subcontract_scope' => $s]);
        };
        $acc = $this->f['acceptance'];
        $lineId = (int) DB::table('customer_acceptance_lines')->where('acceptance_id', $acc)->value('issued_line_id');
        $digest = ScopeHeader::digest((string) DB::table('quotation_issued_lines')->where('id', $lineId)->value('description'));
        // Contradictions between the evidenced declaration and the stored classification, in both directions.
        $this->sqlRefused($insert($this->scopeDeclaration($acc, 'EXCLUDED', 'EXCLUDED'), 'REQUIRED', 'EXCLUDED'));
        $this->sqlRefused($insert($this->scopeDeclaration($acc, 'EXCLUDED', 'EXCLUDED'), 'EXCLUDED', 'REQUIRED'));
        $this->sqlRefused($insert($this->scopeDeclaration($acc, 'REQUIRED', 'REQUIRED'), 'EXCLUDED', 'EXCLUDED'));
        $this->sqlRefused($insert($this->scopeDeclaration($acc, 'UNSTATED', 'EXCLUDED'), 'EXCLUDED', 'EXCLUDED'));
        // The incorrect case: a declaration followed by free text that contradicts it — whatever the columns say.
        $this->sqlRefused($insert($this->scopeDeclaration($acc)."\nMaterials required for the work", 'EXCLUDED', 'EXCLUDED'));
        $this->sqlRefused($insert("MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nMaterials required for the work", 'EXCLUDED', 'EXCLUDED'));
        $this->sqlRefused($insert("MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nMaterials required for the work", 'UNSTATED', 'UNSTATED'));
        // Wrong line id, wrong digest, the digest of another text, uppercase digest.
        $this->sqlRefused($insert("MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nLINE: ".($lineId + 1)."\nDELIVERABLE_SHA256: {$digest}", 'EXCLUDED', 'EXCLUDED'));
        $this->sqlRefused($insert("MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nLINE: {$lineId}\nDELIVERABLE_SHA256: ".str_repeat('a', 64), 'EXCLUDED', 'EXCLUDED'));
        $this->sqlRefused($insert("MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nLINE: {$lineId}\nDELIVERABLE_SHA256: ".ScopeHeader::digest('Another deliverable'), 'EXCLUDED', 'EXCLUDED'));
        $this->sqlRefused($insert("MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nLINE: {$lineId}\nDELIVERABLE_SHA256: ".strtoupper($digest), 'EXCLUDED', 'EXCLUDED'));
        // Flags without any declaration, malformed and reordered declarations, CRLF, keyword lines inside conditions text.
        $this->sqlRefused($insert('No declaration at all.', 'EXCLUDED', 'EXCLUDED'));
        $this->sqlRefused($insert("SUBCONTRACTORS: EXCLUDED\nMATERIALS: EXCLUDED\nLINE: {$lineId}\nDELIVERABLE_SHA256: {$digest}", 'EXCLUDED', 'EXCLUDED'));
        $this->sqlRefused($insert($this->scopeDeclaration($acc)."\nMATERIALS: REQUIRED", 'EXCLUDED', 'EXCLUDED'));
        $this->sqlRefused($insert(str_replace("\n", "\r\n", $this->scopeDeclaration($acc)), 'EXCLUDED', 'EXCLUDED'));
        $this->sqlRefused($insert("Conditions.\nMATERIALS: EXCLUDED", 'UNSTATED', 'UNSTATED'));
        $this->assertSame(1, DB::table('service_completion_bases')->count());
        // Honest rows: a declaration whose columns equal it, and conditions-only text stating no scope.
        ($insert($this->scopeDeclaration($acc, 'REQUIRED', 'EXCLUDED'), 'REQUIRED', 'EXCLUDED'))();
        $this->assertSame(2, DB::table('service_completion_bases')->count());
        $next['version'] = 3;
        $next['predecessor_id'] = (int) DB::table('service_completion_bases')->where('version', 2)->value('id');
        $next['intent_digest'] = str_repeat('d', 64);
        ($insert('The site contact signs off on completion.', 'UNSTATED', 'UNSTATED'))();
        $this->assertSame(3, DB::table('service_completion_bases')->count());
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
        $this->sqlRefused(fn () => DB::table('service_completion_bases')->insert([...$next, 'terms_source' => 'ISSUED_LINE', 'evidence_id' => $row['evidence_id'], 'agreed_text' => $issued, 'materials_scope' => 'UNSTATED', 'subcontract_scope' => 'UNSTATED']));
        // EVIDENCE_TEXT riding on the acceptance's own consent evidence, or on text its evidence does not say.
        $this->sqlRefused(fn () => DB::table('service_completion_bases')->insert([...$next, 'terms_source' => 'EVIDENCE_TEXT', 'evidence_id' => $this->consentEvidence, 'agreed_text' => $issued]));
        $headed = $this->scopeDeclaration($this->f['acceptance'], 'EXCLUDED', 'EXCLUDED');
        $other = $this->appendAgreementEvidence($this->f['case'], $this->checker->id, $headed);
        $this->sqlRefused(fn () => DB::table('service_completion_bases')->insert([...$next, 'terms_source' => 'EVIDENCE_TEXT', 'evidence_id' => $other, 'agreed_text' => $this->scopeDeclaration($this->f['acceptance'], 'EXCLUDED', 'UNSTATED')]));
        // The same evidence twice for one line (unique), a scope outside the vocabulary, a scope on the issued-line path (CHECK).
        $this->sqlRefused(fn () => DB::table('service_completion_bases')->insert([...$next, 'evidence_id' => $row['evidence_id']]));
        $this->sqlRefused(fn () => DB::table('service_completion_bases')->insert([...$next, 'evidence_id' => $other, 'agreed_text' => $headed, 'materials_scope' => 'MAYBE']));
        $this->sqlRefused(fn () => DB::table('service_completion_bases')->insert([...$next, 'terms_source' => 'ISSUED_LINE', 'evidence_id' => $this->consentEvidence, 'agreed_text' => $issued, 'materials_scope' => 'EXCLUDED']));
        // The one honest next version is accepted.
        DB::table('service_completion_bases')->insert([...$next, 'evidence_id' => $other, 'agreed_text' => $headed, 'intent_digest' => str_repeat('b', 64)]);
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
        $old_text = $this->scopeDeclaration($this->f['acceptance']);
        $consentAt = DB::table('customer_decision_evidence')->where('id', $this->consentEvidence)->value('recorded_at');
        $old = DB::table('customer_decision_evidence')->insertGetId(['company_id' => 1, 'case_id' => $this->f['case'], 'source_kind' => 'EMAIL', 'authority_verification_basis' => 'DOCUMENT_ON_FILE',
            'customer_representative_snapshot' => 'Synthetic buyer', 'occurrence_precision' => 'UNKNOWN', 'factual_description' => $old_text, 'observation_state' => 'OBSERVED',
            'recorded_by' => $this->checker->id, 'recorded_at' => DB::raw("'{$consentAt}'::timestamptz - interval '1 hour'")]);
        DB::statement('SET LOCAL session_replication_role = origin');
        $this->sqlRefused(fn () => DB::table('service_completion_bases')->insert([...$row, 'version' => 2, 'predecessor_id' => $good['basis_id'], 'evidence_id' => $old, 'agreed_text' => $old_text]));
        $this->refused(fn () => app(RecordServiceCompletionBasis::class)($this->checker, 'older', $this->f['acceptance'], $old_text, $old));
    }
}
