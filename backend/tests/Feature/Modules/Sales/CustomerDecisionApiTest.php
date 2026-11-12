<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\Company;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Models\CustomerDecisionEvidence;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionRefusal;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionRefusalStatus;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

/**
 * Slice 1 of customer acceptance over HTTP: record what the customer sent. Default-deny while both duties are STAGED; then open / append / show / list with idempotent writes,
 * stable reason codes, an independent read duty, company isolation and no consent anywhere in an answer.
 */
class CustomerDecisionApiTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    private User $clerk;

    private User $viewer;

    private User $recorderOnly;

    /** @var array{family: int, scope: int} */
    private array $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        foreach ([P::CUSTOMER_DECISION_VIEW, P::CUSTOMER_DECISION_EVIDENCE_RECORD] as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $this->clerk = User::factory()->create();
        $this->clerk->givePermissionTo([P::CUSTOMER_DECISION_VIEW, P::CUSTOMER_DECISION_EVIDENCE_RECORD, P::QUOTATION_VIEW]);
        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo([P::CUSTOMER_DECISION_VIEW]);
        $this->recorderOnly = User::factory()->create();
        $this->recorderOnly->givePermissionTo([P::CUSTOMER_DECISION_EVIDENCE_RECORD]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $line = QuotationDraftLine::query()->where('revision_id', $this->preparedDraft()->id)->orderBy('id')->firstOrFail();
        $this->scope = ['family' => $line->family_id, 'scope' => $line->logical_scope_id];
    }

    private function activate(): void
    {
        $names = [P::QUOTATION_PREPARE, P::QUOTATION_VIEW, P::CUSTOMER_DECISION_VIEW, P::CUSTOMER_DECISION_EVIDENCE_RECORD];
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest(array_map(fn (SalesDuty $d): SalesDuty => in_array($d->name, $names, true)
            ? new SalesDuty($d->name, $d->class, DutyStatus::Active, $d->operation, $d->projection, $d->deliveryGate, $d->scope) : $d, SalesDutyManifest::shipped()->all())));
    }

    /** @return array<string, mixed> */
    private function openBody(string $identity = 'PO-7001', bool $withComponent = true): array
    {
        return ['party_id' => $this->customer->id, 'intake_identity' => $identity] + ($withComponent
            ? ['components' => [['intent_identity' => 'whole-quote', 'family_id' => $this->scope['family'], 'original_scope_anchor_id' => $this->scope['scope']]]] : []);
    }

    /** @return array<string, mixed> */
    private function observation(int $generation, array $over = []): array
    {
        return $over + ['expected_generation' => $generation, 'source_kind' => 'EMAIL', 'authority_verification_basis' => 'STATED_BY_CUSTOMER', 'occurrence_precision' => 'UNKNOWN',
            'factual_description' => 'The customer wrote that they would like to go ahead.'];
    }

    private function key(string $k): array
    {
        return ['Idempotency-Key' => $k];
    }

    public function test_everything_is_refused_while_the_duties_are_staged_and_unauthenticated_callers_get_401(): void
    {
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->getJson('/api/v1/customer-decision-cases')->assertUnauthorized();
        $this->postJson('/api/v1/customer-decision-cases', $this->openBody(), $this->key('k'))->assertUnauthorized();
        Sanctum::actingAs($this->clerk);
        $this->getJson('/api/v1/customer-decision-cases')->assertForbidden();
        $this->getJson('/api/v1/customer-decision-cases/1')->assertForbidden();
        $this->postJson('/api/v1/customer-decision-cases', $this->openBody(), $this->key('k'))->assertForbidden();
        $this->postJson('/api/v1/customer-decision-cases/1/evidence', $this->observation(0), $this->key('k'))->assertForbidden();
        $this->assertSame(0, CustomerDecisionCase::query()->count());
    }

    public function test_the_whole_flow_opens_appends_corrects_reads_and_lists_without_any_consent(): void
    {
        $this->activate();
        Sanctum::actingAs($this->clerk);

        $opened = $this->postJson('/api/v1/customer-decision-cases', $this->openBody(), $this->key('open-1'))->assertCreated()
            ->assertJsonPath('data.generation', 0)->assertJsonPath('data.replayed', false)->assertJsonPath('data.evidence_id', null);
        $caseId = $opened->json('data.case_id');
        $this->postJson('/api/v1/customer-decision-cases', $this->openBody(), $this->key('open-1'))->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.case_id', $caseId);
        $this->postJson('/api/v1/customer-decision-cases', $this->openBody('PO-OTHER'), $this->key('open-1'))->assertStatus(409)->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');
        $this->postJson('/api/v1/customer-decision-cases', $this->openBody(), $this->key('open-2'))->assertStatus(409)->assertJsonPath('reason_code', 'INTAKE_IDENTITY_EXISTS');
        $this->postJson('/api/v1/customer-decision-cases', $this->openBody('PO-NOKEY'))->assertStatus(422)->assertJsonPath('reason_code', 'REQUEST_KEY_REQUIRED');

        $first = $this->postJson("/api/v1/customer-decision-cases/{$caseId}/evidence", $this->observation(0, ['external_reference' => 'PO-7001', 'occurrence_precision' => 'DATE', 'occurred_at' => '2026-11-10', 'source_timezone' => 'Asia/Karachi']), $this->key('ev-1'))
            ->assertCreated()->assertJsonPath('data.generation', 1);
        $this->postJson("/api/v1/customer-decision-cases/{$caseId}/evidence", $this->observation(0, ['external_reference' => 'PO-7001', 'occurrence_precision' => 'DATE', 'occurred_at' => '2026-11-10', 'source_timezone' => 'Asia/Karachi']), $this->key('ev-1'))
            ->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.evidence_id', $first->json('data.evidence_id'));
        $this->postJson("/api/v1/customer-decision-cases/{$caseId}/evidence", $this->observation(0), $this->key('ev-2'))->assertStatus(409)->assertJsonPath('reason_code', 'DECISION_CASE_STALE');
        $this->postJson("/api/v1/customer-decision-cases/{$caseId}/evidence", $this->observation(1, ['factual_description' => 'Corrected.', 'predecessor_id' => $first->json('data.evidence_id')]), $this->key('ev-3'))
            ->assertCreated()->assertJsonPath('data.generation', 2);
        $this->postJson("/api/v1/customer-decision-cases/{$caseId}/evidence", $this->observation(2, ['predecessor_id' => $first->json('data.evidence_id')]), $this->key('ev-4'))
            ->assertStatus(422)->assertJsonPath('reason_code', 'EVIDENCE_PREDECESSOR_INVALID');

        // A date with no offset is the START of that day in the stated zone, not in the server's.
        $this->assertSame('2026-11-09 19:00:00', CustomerDecisionEvidence::query()->orderBy('id')->firstOrFail()->occurred_at->setTimezone('UTC')->format('Y-m-d H:i:s'));

        $detail = $this->getJson("/api/v1/customer-decision-cases/{$caseId}")->assertOk()
            ->assertJsonPath('data.case.intake_identity', 'PO-7001')->assertJsonPath('data.case.party_name', 'Customer Ltd')->assertJsonPath('data.case.generation', 2)
            ->assertJsonPath('data.case.consent_recorded', false)->assertJsonMissingPath('data.hidden_component_count')->assertJsonCount(1, 'data.components')->assertJsonCount(2, 'data.observations');
        $this->assertSame([false, true], array_column($detail->json('data.observations'), 'is_current'));
        $this->getJson('/api/v1/customer-decision-cases')->assertOk()->assertJsonPath('data.0.id', $caseId)->assertJsonPath('data.0.observation_count', 2)->assertJsonPath('data.0.component_count', 1)
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/customer-decision-cases?filter[search]=po-70')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/customer-decision-cases?filter[search]=nothing')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/customer-decision-cases?filter[party_id]=999')->assertOk()->assertJsonCount(0, 'data');

        $all = json_encode([$detail->json(), $this->getJson('/api/v1/customer-decision-cases')->json()]);
        foreach (['accept', 'amount', 'price', 'cost', 'margin', 'claim'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower(str_replace(['consent_recorded', 'authority_verification_basis'], '', $all)), "no {$forbidden} in a case answer");
        }
    }

    public function test_malformed_bodies_are_422_and_unknown_cases_are_404_after_the_duty_is_checked(): void
    {
        $this->activate();
        Sanctum::actingAs($this->clerk);
        $this->postJson('/api/v1/customer-decision-cases', ['intake_identity' => 'X'], $this->key('k1'))->assertStatus(422)->assertJsonValidationErrors('party_id');
        $this->postJson('/api/v1/customer-decision-cases', $this->openBody('PO-9') + ['accepted' => true], $this->key('k2'))->assertStatus(422)->assertJsonValidationErrors('accepted');
        $this->postJson('/api/v1/customer-decision-cases', $this->openBody('PO-9'), $this->key('k2b'))->assertCreated();
        $this->postJson('/api/v1/customer-decision-cases', ['party_id' => 999999, 'intake_identity' => 'PO-10'], $this->key('k3'))->assertStatus(422)->assertJsonPath('reason_code', 'INVALID_INPUT');
        $this->postJson('/api/v1/customer-decision-cases', ['party_id' => $this->customer->id, 'intake_identity' => 'PO-11', 'components' => [['intent_identity' => 'x', 'family_id' => 999999, 'original_scope_anchor_id' => 1]]], $this->key('k4'))
            ->assertStatus(422)->assertJsonPath('reason_code', 'INVALID_INPUT');
        $case = CustomerDecisionCase::query()->sole()->id;
        foreach ([['source_kind' => 'TELEPATHY'], ['authority_verification_basis' => 'TRUST_ME'], ['occurrence_precision' => 'SOMETIME'], ['factual_description' => ''], ['accepted' => true], ['occurred_at' => 'not a date']] as $i => $bad) {
            $status = $this->postJson("/api/v1/customer-decision-cases/{$case}/evidence", $this->observation(0, $bad), $this->key("bad-{$i}"))->status();
            $this->assertSame(422, $status, json_encode($bad));
        }
        // An UNKNOWN precision with a date is refused by the database rules, reported as INVALID_INPUT; so is an interval that ends before it starts.
        $this->postJson("/api/v1/customer-decision-cases/{$case}/evidence", $this->observation(0, ['occurred_at' => '2026-11-10']), $this->key('unk'))->assertStatus(422)->assertJsonPath('reason_code', 'INVALID_INPUT');
        $this->postJson("/api/v1/customer-decision-cases/{$case}/evidence", $this->observation(0, ['occurrence_precision' => 'INTERVAL', 'occurred_at' => '2026-11-11 10:00', 'occurred_until' => '2026-11-11 09:00']), $this->key('rev'))
            ->assertStatus(422)->assertJsonPath('reason_code', 'INVALID_INPUT');
        $this->assertSame(0, CustomerDecisionEvidence::query()->count());
        // A stated date with no zone is read in the COMPANY's zone and stored with it.
        $this->postJson("/api/v1/customer-decision-cases/{$case}/evidence", $this->observation(0, ['occurrence_precision' => 'DATE', 'occurred_at' => '2026-11-10']), $this->key('nz'))->assertCreated();
        $stored = CustomerDecisionEvidence::query()->sole();
        $this->assertSame(Company::findOrFail(1)->timezone, $stored->source_timezone);
        $this->assertSame('2026-11-10', $stored->occurred_at->setTimezone($stored->source_timezone)->format('Y-m-d'));
        $this->getJson('/api/v1/customer-decision-cases/999999')->assertNotFound();
        $this->postJson('/api/v1/customer-decision-cases/999999/evidence', $this->observation(0), $this->key('nf'))->assertNotFound();
        $this->getJson('/api/v1/customer-decision-cases?per_page=1000')->assertStatus(422);
    }

    public function test_read_and_record_are_independent_duties(): void
    {
        $this->activate();
        $case = CustomerDecisionCase::query()->create(['party_id' => $this->customer->id, 'intake_identity' => 'PO-IND', 'generation' => 0, 'recorded_by' => $this->clerk->id, 'recorded_at' => now()])->id;

        Sanctum::actingAs($this->viewer);
        $this->getJson('/api/v1/customer-decision-cases')->assertOk();
        $this->getJson("/api/v1/customer-decision-cases/{$case}")->assertOk();
        $this->postJson('/api/v1/customer-decision-cases', $this->openBody('PO-NO', false), $this->key('v1'))->assertForbidden();
        $this->postJson("/api/v1/customer-decision-cases/{$case}/evidence", $this->observation(0), $this->key('v2'))->assertForbidden();

        Sanctum::actingAs($this->recorderOnly);
        $this->getJson('/api/v1/customer-decision-cases')->assertForbidden();
        $this->getJson("/api/v1/customer-decision-cases/{$case}")->assertForbidden();
        $this->postJson('/api/v1/customer-decision-cases', $this->openBody('PO-YES', false), $this->key('r1'))->assertCreated();
        // Recording a case WITH components needs the independent right to see the quotation family.
        $this->postJson('/api/v1/customer-decision-cases', $this->openBody('PO-COMP'), $this->key('r2'))->assertForbidden();

        foreach ([$this->maker, $this->checker] as $person) {
            Sanctum::actingAs($person);
            $this->getJson('/api/v1/customer-decision-cases')->assertForbidden();
            $this->postJson('/api/v1/customer-decision-cases', $this->openBody('PO-NOPE', false), $this->key('m'.$person->id))->assertForbidden();
        }
    }

    public function test_a_viewer_without_quotation_view_sees_the_case_but_no_component_information(): void
    {
        $this->activate();
        Sanctum::actingAs($this->clerk);
        $caseId = $this->postJson('/api/v1/customer-decision-cases', $this->openBody(), $this->key('o'))->assertCreated()->json('data.case_id');

        Sanctum::actingAs($this->viewer);
        $detail = $this->getJson("/api/v1/customer-decision-cases/{$caseId}")->assertOk()->assertJsonCount(0, 'data.components')->assertJsonMissingPath('data.hidden_component_count');
        $this->assertStringNotContainsString('hidden', (string) $detail->getContent());
        $list = $this->getJson('/api/v1/customer-decision-cases')->assertOk()->assertJsonPath('data.0.component_count', null)->assertJsonPath('meta.total', 1);
        $this->assertArrayHasKey('component_count', $list->json('data.0'));
        $this->getJson('/api/v1/customer-decision-cases?sort=component_count')->assertStatus(422);
    }

    public function test_the_component_count_covers_only_quotation_families_the_viewer_may_see(): void
    {
        $this->activate();
        $mine = $this->scope;
        $realMaker = $this->maker;
        $this->maker = User::factory()->create();
        $this->maker->givePermissionTo([P::QUOTATION_PREPARE, P::QUOTATION_VIEW]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $line = QuotationDraftLine::query()->where('revision_id', $this->preparedDraft()->id)->orderBy('id')->firstOrFail();
        $theirs = ['family' => $line->family_id, 'scope' => $line->logical_scope_id];
        $this->maker = $realMaker;
        $this->assertNotSame($mine['family'], $theirs['family']);

        Sanctum::actingAs($this->clerk);
        $body = $this->openBody('PO-MIXED');
        $body['components'][] = ['intent_identity' => 'other-quote', 'family_id' => $theirs['family'], 'original_scope_anchor_id' => $theirs['scope']];
        $caseId = $this->postJson('/api/v1/customer-decision-cases', $body, $this->key('mixed'))->assertCreated()->json('data.case_id');
        $this->assertSame(2, DB::table('customer_decision_components')->where('case_id', $caseId)->count());

        // The preparer owns only the first family and holds quotation view limited to own records.
        $this->maker->givePermissionTo([P::CUSTOMER_DECISION_VIEW]);
        AccessScopeLimit::create(['user_id' => $this->maker->id, 'permission' => P::QUOTATION_VIEW, 'scope' => 'OWN']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->maker);
        $list = $this->getJson('/api/v1/customer-decision-cases')->assertOk()->assertJsonPath('data.0.component_count', 1)->assertJsonPath('meta.total', 1);
        $this->assertStringNotContainsString('"component_count":2', (string) $list->getContent());
        $detail = $this->getJson("/api/v1/customer-decision-cases/{$caseId}")->assertOk()->assertJsonCount(1, 'data.components')->assertJsonMissingPath('data.hidden_component_count');
        $this->assertSame($mine['family'], $detail->json('data.components.0.family_id'));
        $this->assertStringNotContainsString((string) $theirs['scope'].',"recorded', (string) $detail->getContent());

        // The clerk sees everything and so counts both.
        Sanctum::actingAs($this->clerk);
        $this->getJson('/api/v1/customer-decision-cases')->assertOk()->assertJsonPath('data.0.component_count', 2);
    }

    public function test_a_quotation_only_user_is_refused_the_customer_messages_list_and_case(): void
    {
        $this->activate();
        Sanctum::actingAs($this->clerk);
        $caseId = $this->postJson('/api/v1/customer-decision-cases', $this->openBody(), $this->key('o'))->assertCreated()->json('data.case_id');

        Sanctum::actingAs($this->maker);
        $this->getJson('/api/v1/customer-decision-cases')->assertForbidden();
        $this->getJson("/api/v1/customer-decision-cases/{$caseId}")->assertForbidden();
        $this->getJson('/api/v1/customer-decision-cases/999999')->assertForbidden();
    }

    public function test_a_limited_scope_of_either_duty_fails_closed_and_a_suspended_member_is_refused(): void
    {
        $this->activate();
        $case = CustomerDecisionCase::query()->create(['party_id' => $this->customer->id, 'intake_identity' => 'PO-LIM', 'generation' => 0, 'recorded_by' => $this->clerk->id, 'recorded_at' => now()])->id;
        AccessScopeLimit::create(['user_id' => $this->viewer->id, 'permission' => P::CUSTOMER_DECISION_VIEW, 'scope' => 'OWN']);
        AccessScopeLimit::create(['user_id' => $this->recorderOnly->id, 'permission' => P::CUSTOMER_DECISION_EVIDENCE_RECORD, 'scope' => 'OWN']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->viewer);
        $this->getJson('/api/v1/customer-decision-cases')->assertForbidden();
        $this->getJson("/api/v1/customer-decision-cases/{$case}")->assertForbidden();
        Sanctum::actingAs($this->recorderOnly);
        $this->postJson('/api/v1/customer-decision-cases', $this->openBody('PO-LIM2', false), $this->key('l1'))->assertForbidden();
        $this->postJson("/api/v1/customer-decision-cases/{$case}/evidence", $this->observation(0), $this->key('l2'))->assertForbidden();

        $this->clerk->forceFill(['status' => MemberStatus::Suspended->value])->save();
        Sanctum::actingAs($this->clerk);
        $this->getJson('/api/v1/customer-decision-cases')->assertForbidden();
        $this->postJson('/api/v1/customer-decision-cases', $this->openBody('PO-SUSP', false), $this->key('s1'))->assertForbidden();
        $this->assertSame(1, CustomerDecisionCase::query()->count());
    }

    public function test_another_company_is_isolated_in_both_directions_and_probes_look_like_unknown_ids(): void
    {
        $this->activate();
        Sanctum::actingAs($this->clerk);
        $ours = $this->postJson('/api/v1/customer-decision-cases', $this->openBody('PO-OURS', false), $this->key('o'))->assertCreated()->json('data.case_id');

        $other = DB::table('companies')->insertGetId(['name' => 'Other Co', 'base_currency_id' => Company::findOrFail(1)->base_currency_id, 'created_at' => now(), 'updated_at' => now()]);
        $outsiderId = DB::table('users')->insertGetId(['company_id' => $other, 'name' => 'Outsider', 'email' => 'outsider@other.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $outsider = User::withoutGlobalScopes()->findOrFail($outsiderId);
        // Permissions are per company (team): grant them as a member of the other company.
        app(PermissionRegistrar::class)->setPermissionsTeamId($other);
        $outsider->givePermissionTo([P::CUSTOMER_DECISION_VIEW, P::CUSTOMER_DECISION_EVIDENCE_RECORD, P::QUOTATION_VIEW]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $foreignParty = DB::table('parties')->insertGetId(['company_id' => $other, 'code' => 'FP', 'name' => 'Foreign Party', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = DB::table('customer_decision_cases')->insertGetId(['company_id' => $other, 'party_id' => $foreignParty, 'intake_identity' => 'PO-THEIRS', 'generation' => 0, 'recorded_by' => $outsiderId, 'recorded_at' => now()]);

        // One of ours probing the other company's case: exactly an unknown id.
        $unknown = $this->probe(999999);
        $this->assertSame($unknown, $this->probe($foreign), 'another company\'s case looks like an unknown one');
        $this->assertSame(404, $unknown[0]);
        $list = $this->getJson('/api/v1/customer-decision-cases')->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString('PO-THEIRS', (string) $list->getContent());
        $this->postJson('/api/v1/customer-decision-cases', ['party_id' => $foreignParty, 'intake_identity' => 'PO-X'], $this->key('fp'))->assertStatus(422)->assertJsonPath('reason_code', 'INVALID_INPUT');

        // One of theirs probing ours: the same 404 as an unknown id; the list shows only theirs; their intake identity may equal ours (identities are per company).
        Sanctum::actingAs($outsider);
        $this->assertSame($this->probe(999999), $this->probe($ours), 'an outsider cannot tell our case from an unknown id');
        $this->assertContains($this->probe($ours)[0], [403, 404]);
        $theirs = $this->getJson('/api/v1/customer-decision-cases');
        $this->assertStringNotContainsString('PO-OURS', (string) $theirs->getContent());
        $this->assertSame(0, DB::table('customer_decision_evidence')->count(), 'no evidence was written by a probe');
    }

    /** A case opened at a stated instant, optionally linked to the prepared quotation. */
    private function caseAt(string $identity, string $utc, bool $linked): int
    {
        // The database stamps recorded_at with its own clock; for this fixture only, let the stated instant stand (rolled back with the test transaction).
        DB::statement('ALTER TABLE customer_decision_cases DISABLE TRIGGER customer_decision_cases_stamp');
        $id = CustomerDecisionCase::query()->create(['party_id' => $this->customer->id, 'intake_identity' => $identity, 'generation' => 0, 'recorded_by' => $this->clerk->id, 'recorded_at' => $utc])->id;
        if ($linked) {
            DB::table('customer_decision_components')->insert(['company_id' => 1, 'case_id' => $id, 'intent_identity' => 'whole-quote', 'family_id' => $this->scope['family'], 'original_scope_anchor_id' => $this->scope['scope'], 'recorded_by' => $this->clerk->id, 'recorded_at' => $utc]);
        }

        return $id;
    }

    /** @return list<string> */
    private function listed(string $query): array
    {
        return array_column($this->getJson('/api/v1/customer-decision-cases?'.$query)->assertOk()->json('data'), 'intake_identity');
    }

    public function test_the_opened_between_filter_is_inclusive_on_the_company_calendar_day(): void
    {
        $this->activate();
        DB::table('companies')->where('id', 1)->update(['timezone' => 'Asia/Karachi']); // UTC+5
        Sanctum::actingAs($this->clerk);
        // Karachi days: 10 Nov 00:00 = 09 Nov 19:00 UTC; 10 Nov 23:59:59 = 10 Nov 18:59:59 UTC.
        $this->caseAt('BEFORE', '2026-11-09 18:59:59', false);
        $this->caseAt('FIRST', '2026-11-09 19:00:00', false);
        $this->caseAt('LAST', '2026-11-10 18:59:59', false);
        $this->caseAt('AFTER', '2026-11-10 19:00:00', false);

        $this->assertEqualsCanonicalizing(['FIRST', 'LAST'], $this->listed('filter[recorded_from]=2026-11-10&filter[recorded_to]=2026-11-10'));
        $this->assertEqualsCanonicalizing(['FIRST', 'LAST', 'AFTER'], $this->listed('filter[recorded_from]=2026-11-10'));
        $this->assertEqualsCanonicalizing(['BEFORE', 'FIRST', 'LAST'], $this->listed('filter[recorded_to]=2026-11-10'));
        $this->assertSame([], $this->listed('filter[recorded_from]=2026-12-01'));
    }

    public function test_the_filter_dates_are_validated(): void
    {
        $this->activate();
        Sanctum::actingAs($this->clerk);
        foreach (['filter[recorded_from]=10-11-2026', 'filter[recorded_from]=2026-02-30', 'filter[recorded_to]=tomorrow', 'filter[recorded_from]=2026-11-10&filter[recorded_to]=2026-11-09',
            'filter[quotation_family_id]=abc', 'filter[quotation_family_id]=0', 'filter[recorded_from][]=2026-11-10'] as $bad) {
            $this->getJson('/api/v1/customer-decision-cases?'.$bad)->assertStatus(422);
        }
    }

    public function test_the_linked_quotation_filter_matches_only_cases_with_a_component_on_that_family(): void
    {
        $this->activate();
        Sanctum::actingAs($this->clerk);
        $this->caseAt('LINKED', '2026-11-10 08:00:00', true);
        $this->caseAt('PLAIN', '2026-11-10 09:00:00', false);

        $this->assertSame(['LINKED'], $this->listed('filter[quotation_family_id]='.$this->scope['family']));
        $this->assertSame([], $this->listed('filter[quotation_family_id]=999999'));
        $this->assertSame(['LINKED'], $this->listed('filter[quotation_family_id]='.$this->scope['family'].'&filter[recorded_from]=2026-11-10&filter[recorded_to]=2026-11-10'));
        $this->assertSame([], $this->listed('filter[quotation_family_id]='.$this->scope['family'].'&filter[recorded_from]=2026-11-11'));
    }

    public function test_the_linked_quotation_filter_never_reveals_cases_of_a_quotation_the_actor_cannot_see(): void
    {
        $this->activate();
        $this->caseAt('LINKED', '2026-11-10 08:00:00', true);
        $family = $this->scope['family'];

        // No quotation duty at all, and a quotation duty limited to own records (the maker prepared this one, this user did not): both look like an unknown quotation, while the unfiltered list is unchanged.
        $own = User::factory()->create();
        $own->givePermissionTo([P::CUSTOMER_DECISION_VIEW, P::QUOTATION_VIEW]);
        AccessScopeLimit::create(['user_id' => $own->id, 'permission' => P::QUOTATION_VIEW, 'scope' => 'OWN']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([$this->viewer, $own] as $person) {
            Sanctum::actingAs($person);
            $this->assertSame(['LINKED'], $this->listed(''), 'the unfiltered list is unchanged');
            $this->assertSame([], $this->listed('filter[quotation_family_id]='.$family));
            $this->assertSame($this->getJson('/api/v1/customer-decision-cases?filter[quotation_family_id]=999999')->json('data'), $this->getJson('/api/v1/customer-decision-cases?filter[quotation_family_id]='.$family)->json('data'));
        }

        // The preparer owns it and sees it.
        $this->maker->givePermissionTo([P::CUSTOMER_DECISION_VIEW]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->maker);
        $this->assertSame(['LINKED'], $this->listed('filter[quotation_family_id]='.$family));
    }

    public function test_the_filters_never_cross_companies(): void
    {
        $this->activate();
        $this->caseAt('OURS', '2026-11-10 08:00:00', true);
        $other = DB::table('companies')->insertGetId(['name' => 'Other Co', 'base_currency_id' => Company::findOrFail(1)->base_currency_id, 'created_at' => now(), 'updated_at' => now()]);
        $outsiderId = DB::table('users')->insertGetId(['company_id' => $other, 'name' => 'Outsider', 'email' => 'outsider2@other.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $outsider = User::withoutGlobalScopes()->findOrFail($outsiderId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($other);
        $outsider->givePermissionTo([P::CUSTOMER_DECISION_VIEW, P::QUOTATION_VIEW]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The other company's member (who may or may not hold the duties there) never receives one of our cases through either filter.
        Sanctum::actingAs($outsider);
        foreach (['filter[quotation_family_id]='.$this->scope['family'], 'filter[recorded_from]=2026-11-10&filter[recorded_to]=2026-11-10'] as $query) {
            $answer = $this->getJson('/api/v1/customer-decision-cases?'.$query);
            $this->assertStringNotContainsString('OURS', (string) $answer->getContent());
            $this->assertContains($answer->status(), [200, 403]);
            if ($answer->status() === 200) {
                $answer->assertJsonCount(0, 'data');
            }
        }
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function probe(int $case): array
    {
        $read = $this->getJson("/api/v1/customer-decision-cases/{$case}");
        $write = $this->postJson("/api/v1/customer-decision-cases/{$case}/evidence", $this->observation(0), $this->key('probe-'.bin2hex(random_bytes(4))));
        $this->assertSame($read->status(), $write->status());
        $body = (array) json_decode((string) $read->getContent(), true);

        return [$read->status(), ['message' => $body['message'] ?? null]];
    }

    public function test_every_refusal_code_has_an_explicit_http_status(): void
    {
        $codes = array_values((new ReflectionClass(CustomerDecisionRefusal::class))->getConstants());
        $this->assertEqualsCanonicalizing($codes, array_keys(CustomerDecisionRefusalStatus::MAP), 'a new refusal code needs a decided status');
        $this->assertSame(422, CustomerDecisionRefusalStatus::for('SOMETHING_ELSE'));
    }
}
