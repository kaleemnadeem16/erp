<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Models\CustomerDecisionComponent;
use App\Modules\Sales\Models\CustomerDecisionEvidence;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Support\Authority\SalesDevPreview;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

class CustomerAcceptanceApiTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    private int $revision;

    private array $intent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped()->withActivated([P::QUOTATION_PREPARE, P::QUOTATION_VIEW, P::QUOTATION_ISSUE, P::CUSTOMER_DECISION_VIEW, P::CUSTOMER_DECISION_ACCEPT]));
        foreach ([P::CUSTOMER_DECISION_VIEW, P::CUSTOMER_DECISION_ACCEPT] as $duty) {
            Permission::findOrCreate($duty, 'web');
            $this->checker->givePermissionTo($duty);
        }
        $r = $this->preparedDraft();
        app(IssueQuotation::class)($this->checker, $r->id, $r->input_generation, 'issue');
        $this->revision = $r->id;
        $l = QuotationIssuedLine::where('revision_id', $r->id)->orderBy('id')->firstOrFail();
        $c = CustomerDecisionCase::create(['party_id' => $this->customer->id, 'intake_identity' => 'Synthetic API consent', 'recorded_by' => $this->checker->id]);
        $d = CustomerDecisionComponent::create(['case_id' => $c->id, 'intent_identity' => 'scope', 'family_id' => $r->family_id, 'original_scope_anchor_id' => $l->logical_scope_id, 'recorded_by' => $this->checker->id]);
        $e = CustomerDecisionEvidence::create(['case_id' => $c->id, 'source_kind' => 'EMAIL', 'authority_verification_basis' => 'DOCUMENT_ON_FILE', 'customer_representative_snapshot' => 'Synthetic buyer', 'occurrence_precision' => 'UNKNOWN', 'factual_description' => 'The buyer agrees to the issued full scope unchanged.', 'observation_state' => 'OBSERVED', 'recorded_by' => $this->checker->id]);
        $this->intent = ['component_id' => $d->id, 'evidence_id' => $e->id, 'issued_line_id' => $l->id, 'expected_case_generation' => 0, 'expected_scope_generation' => 0];
    }

    private function previewUrl(): string
    {
        return "/api/v1/quotations/{$this->revision}/acceptance-preview?".http_build_query($this->intent);
    }

    private function recordUrl(): string
    {
        return "/api/v1/quotations/{$this->revision}/acceptances";
    }

    public function test_http_flow_replays_reads_readiness_and_adds_safe_issued_scope_facts(): void
    {
        Sanctum::actingAs($this->checker);
        $p = $this->getJson($this->previewUrl())->assertOk()->assertJsonPath('data.terms.quantity', '2.0000')->assertJsonPath('data.warnings.0', 'DELIVERY_NOT_RECORDED')->assertJsonMissingPath('data.cost');
        $body = $this->intent + ['preview_token' => $p->json('data.preview_token'), 'unchanged_terms' => true];
        $a = $this->postJson($this->recordUrl(), $body, ['Idempotency-Key' => 'accept'])->assertCreated()->assertJsonPath('data.replayed', false)->json('data.acceptance_id');
        $this->postJson($this->recordUrl(), $body, ['Idempotency-Key' => 'accept'])->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.acceptance_id', $a);
        $this->getJson("/api/v1/customer-acceptances/{$a}")->assertOk()->assertJsonPath('data.scope.net', '200.0000')->assertJsonPath('data.unchanged_terms', true)->assertJsonMissingPath('data.intent_digest');
        $this->getJson("/api/v1/customer-acceptances/{$a}/readiness")->assertUnprocessable()->assertJsonPath('reason_code', 'HANDOVER_PATH_UNSUPPORTED');
        $this->getJson("/api/v1/customer-acceptances/{$a}/readiness?path=GOODS_ORDER")->assertUnprocessable()->assertJsonPath('reason_code', 'HANDOVER_PATH_UNSUPPORTED');
        $this->getJson("/api/v1/quotations/{$this->revision}/issued")->assertOk()->assertJsonPath('data.scope_acceptance.0.status', 'ACCEPTED')->assertJsonPath('data.scope_acceptance.1.status', 'AVAILABLE');
        $this->getJson('/api/v1/customer-decision-cases/'.CustomerDecisionCase::firstOrFail()->id)->assertOk()->assertJsonPath('data.case.consent_recorded', true);
        $this->postJson($this->recordUrl(), array_replace($body, ['expected_case_generation' => 1]), ['Idempotency-Key' => 'accept'])->assertConflict()->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');
    }

    public function test_staged_duties_have_no_http_access_even_with_permission_rows_and_are_absent_from_dev_preview(): void
    {
        $this->getJson($this->previewUrl())->assertUnauthorized();
        Sanctum::actingAs($this->checker);
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->getJson($this->previewUrl())->assertForbidden();
        $this->postJson($this->recordUrl(), $this->intent)->assertForbidden();
        $this->getJson('/api/v1/customer-acceptances/1')->assertForbidden();
        $this->getJson('/api/v1/customer-acceptances/1/readiness')->assertForbidden();
        $this->assertNotContains(P::CUSTOMER_DECISION_ACCEPT, SalesDevPreview::activated());
        $this->assertNotContains(P::CUSTOMER_DECISION_ACCEPT, P::all());
    }

    public function test_confirmation_and_exact_payload_are_required_and_no_unknown_money_or_actor_fields_are_accepted(): void
    {
        Sanctum::actingAs($this->checker);
        $token = $this->getJson($this->previewUrl())->assertOk()->json('data.preview_token');
        $body = $this->intent + ['preview_token' => $token, 'unchanged_terms' => true];
        $this->postJson($this->recordUrl(), $body + ['quantity' => '1.0000', 'actor_id' => $this->maker->id], ['Idempotency-Key' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors(['quantity', 'actor_id']);
        $this->postJson($this->recordUrl(), array_replace($body, ['unchanged_terms' => false]), ['Idempotency-Key' => 'bad'])->assertUnprocessable();
        $this->postJson($this->recordUrl(), $body)->assertUnprocessable()->assertJsonPath('reason_code', 'REQUEST_KEY_REQUIRED');
        $this->postJson($this->recordUrl(), array_replace($body, ['preview_token' => 'invalid']), ['Idempotency-Key' => 'bad'])->assertConflict()->assertJsonPath('reason_code', 'PREVIEW_TOKEN_STALE');
        $this->assertDatabaseCount('customer_acceptances', 0);
        $this->assertDatabaseCount('sales_customer_decision_receipts', 0);
    }

    public function test_read_and_accept_are_independent_and_limited_scope_cannot_be_rescued_by_view(): void
    {
        Sanctum::actingAs($this->checker);
        AccessScopeLimit::create(['user_id' => $this->checker->id, 'permission' => P::CUSTOMER_DECISION_ACCEPT, 'scope' => 'MY_UNITS']);
        $this->getJson($this->previewUrl())->assertForbidden();
        $this->postJson($this->recordUrl(), $this->intent)->assertForbidden();
        AccessScopeLimit::query()->delete();
        $this->checker->revokePermissionTo(P::CUSTOMER_DECISION_VIEW);
        $this->getJson($this->previewUrl())->assertForbidden();
    }

    public function test_a_real_acceptance_is_hidden_when_the_active_company_changes(): void
    {
        Sanctum::actingAs($this->checker);
        $token = $this->getJson($this->previewUrl())->assertOk()->json('data.preview_token');
        $a = $this->postJson($this->recordUrl(), $this->intent + ['preview_token' => $token, 'unchanged_terms' => true], ['Idempotency-Key' => 'accept'])->assertCreated()->json('data.acceptance_id');
        $company = DB::table('companies')->insertGetId(['name' => 'Synthetic second company', 'created_at' => now(), 'updated_at' => now()]);
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
        $actor->givePermissionTo([P::CUSTOMER_DECISION_ACCEPT, P::CUSTOMER_DECISION_VIEW, P::QUOTATION_VIEW]);
        Sanctum::actingAs($actor);
        $this->getJson($this->previewUrl())->assertNotFound();
        $this->getJson('/api/v1/customer-acceptances/'.$a)->assertNotFound();
        $this->getJson('/api/v1/customer-acceptances/'.$a.'/readiness')->assertNotFound();
    }

    public function test_cross_company_components_and_acceptances_are_not_found(): void
    {
        Sanctum::actingAs($this->checker);
        $this->intent['component_id'] = 999999;
        $this->getJson($this->previewUrl())->assertNotFound();
        $this->getJson('/api/v1/customer-acceptances/999999')->assertNotFound();
        $foreign = DB::table('companies')->insertGetId(['name' => 'Synthetic foreign company', 'created_at' => now(), 'updated_at' => now()]);
        $userId = DB::table('users')->insertGetId(['company_id' => $foreign, 'name' => 'Synthetic foreign actor', 'email' => 'foreign-ca@example.invalid', 'password' => 'unusable-fixture-password', 'status' => 'ACTIVE']);
        $user = User::withoutGlobalScopes()->findOrFail($userId);
        Sanctum::actingAs($user);
        $this->getJson($this->previewUrl())->assertForbidden();
    }
}
