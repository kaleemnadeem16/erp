<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\ReadDiscountAuthorityConfiguration;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsDiscountAuthority;
use Tests\TestCase;

class DiscountAuthorityApiTest extends TestCase
{
    use BuildsDiscountAuthority;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDiscountAuthority();
        Sanctum::actingAs($this->maker);
    }

    private function policyBody(): array
    {
        return ['code' => 'api-demo', 'priority' => 0, 'operation' => 'ANY', 'enforcement' => 'BOTH', 'effective_from' => $this->today(), 'expiry_calendar_days' => 14, 'reason' => 'DEMO ONLY configuration',
            'bands' => [['rank' => 1, 'label' => 'DEMO ONLY lead', 'exception_only' => false, 'line_percent' => '3', 'document_percent' => '3', 'input_percent' => '3',
                'line_base_amount' => '25000.0000', 'document_base_amount' => '25000.0000', 'input_base_amount' => '25000.0000']]];
    }

    public function test_all_configuration_routes_are_disabled_while_staged_and_direct_reads_are_guarded(): void
    {
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        foreach (['references', 'policies', 'bands', 'assignments', 'mode-events'] as $collection) {
            $this->getJson('/api/v1/sales-discount-authority/'.$collection)->assertForbidden();
        }
        foreach (['references', 'references/1/approval', 'policies', 'policies/1/activation', 'assignments', 'assignments/1/revocation', 'mode'] as $path) {
            $this->postJson('/api/v1/sales-discount-authority/'.$path, [])->assertForbidden();
        }
        $this->expectException(AuthorizationException::class);
        app(ReadDiscountAuthorityConfiguration::class)($this->maker, 'policies');
    }

    public function test_policy_assignment_revocation_and_mode_use_versioned_idempotent_configuration(): void
    {
        $body = ['expected_generation' => $this->authorityGeneration()] + $this->policyBody();
        $key = $this->issueKey();
        $first = $this->postJson('/api/v1/sales-discount-authority/policies', $body, ['Idempotency-Key' => $key])->assertOk();
        $policyId = $first->json('data.id');
        $this->postJson('/api/v1/sales-discount-authority/policies', $body, ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.id', $policyId);
        $this->postJson('/api/v1/sales-discount-authority/policies', array_replace($body, ['priority' => 1]), ['Idempotency-Key' => $key])->assertStatus(409)->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');
        $this->getJson('/api/v1/sales-discount-authority/policies/'.$policyId)->assertOk()->assertJsonPath('data.records.0.code', 'api-demo');
        $this->postJson('/api/v1/sales-discount-authority/policies/'.$policyId.'/activation', ['expected_generation' => $this->authorityGeneration(), 'reason' => 'DEMO ONLY activation'], ['Idempotency-Key' => $this->issueKey()])->assertOk();
        $band = $this->getJson('/api/v1/sales-discount-authority/bands?per_page=1')->assertOk()->assertJsonPath('data.per_page', 1)->json('data.records.0.id');
        $assignment = $this->postJson('/api/v1/sales-discount-authority/assignments', ['expected_generation' => $this->authorityGeneration(), 'user_id' => $this->maker->id, 'band_id' => $band, 'purpose' => 'ROUTINE', 'effective_from' => $this->today(), 'reason' => 'DEMO ONLY named employee'], ['Idempotency-Key' => $this->issueKey()])->assertOk()->json('data.id');
        $this->postJson('/api/v1/sales-discount-authority/assignments/'.$assignment.'/revocation', ['expected_generation' => $this->authorityGeneration(), 'reason' => 'DEMO ONLY revocation'], ['Idempotency-Key' => $this->issueKey()])->assertOk();
        $this->postJson('/api/v1/sales-discount-authority/mode', ['expected_generation' => $this->authorityGeneration(), 'mode' => 'SHADOW', 'reason' => 'DEMO ONLY shadow'], ['Idempotency-Key' => $this->issueKey()])->assertOk()->assertJsonPath('data.mode', 'SHADOW');
        foreach (['policies', 'bands', 'assignments', 'policy-activations', 'revocations', 'mode-events'] as $collection) {
            $this->getJson('/api/v1/sales-discount-authority/'.$collection)->assertOk();
        }
        $this->assertDatabaseCount('sales_discount_policy_versions', 1);
    }

    public function test_reference_proposal_needs_independent_approval_and_binding_response_has_no_prices(): void
    {
        $revision = $this->preparedDraft();
        $reference = $this->postJson('/api/v1/sales-discount-authority/references', ['expected_generation' => $this->authorityGeneration(), 'code' => 'api-reference', 'source_kind' => 'PROJECT_BASELINE',
            'source_key' => 'DEMO ONLY project', 'source_version' => '1', 'family_id' => $revision->family_id, 'manual_measure' => 'job', 'currency_id' => $revision->currency_id, 'unit_price' => '100.0000',
            'effective_from' => $this->today(), 'priority' => 0, 'reason' => 'DEMO ONLY baseline'], ['Idempotency-Key' => $this->issueKey()])->assertOk()->json('data.id');
        Sanctum::actingAs($this->checker);
        $this->getJson('/api/v1/sales-discount-authority/references/'.$reference)->assertOk();
        $this->postJson('/api/v1/sales-discount-authority/references/'.$reference.'/approval', ['expected_generation' => $this->authorityGeneration(), 'reason' => 'DEMO ONLY independent review'], ['Idempotency-Key' => $this->issueKey()])->assertOk();
        Sanctum::actingAs($this->maker);
        $bound = $this->postJson('/api/v1/quotations/'.$revision->id.'/authority-references', ['expected_generation' => $revision->input_generation], ['Idempotency-Key' => $this->issueKey()])->assertOk();
        $this->assertSame(['family_id', 'revision_id', 'bound_lines'], array_keys($bound->json('data')));
    }

    public function test_evidence_is_independently_guarded_scoped_and_history_is_paged(): void
    {
        $revision = $this->discount($this->authorityDraft(), '2');
        $this->authorityPolicy();
        $this->authorityMode('SHADOW');
        $this->discount($revision, '3');
        $uri = '/api/v1/quotations/'.$revision->id.'/discount-authority';
        $this->getJson($uri)->assertForbidden();
        Permission::findOrCreate(Duty::AUTHORITY_EVIDENCE_VIEW, 'web');
        $this->app->instance(SalesDutyManifest::class, app(SalesDutyManifest::class)->withActivated([Duty::AUTHORITY_EVIDENCE_VIEW]));
        $this->maker->givePermissionTo(Duty::AUTHORITY_EVIDENCE_VIEW);
        $this->getJson($uri)->assertOk()->assertJsonPath('data.current.reduction_base_amount', '6.0000')->assertJsonPath('data.current.required_band.line_percent', '3.0000')->assertJsonPath('data.current.required_band.enforcement', 'BOTH');
        $this->getJson($uri.'/history?per_page=1')->assertOk()->assertJsonPath('data.per_page', 1)->assertJsonCount(1, 'data.records')->assertJsonPath('data.records.0.evaluation.required_band.line_percent', '3.0000');
        DB::table('users')->where('id', $this->maker->id)->update(['status' => 'SUSPENDED']);
        $this->expectException(AuthorizationException::class);
        app(ReadDiscountAuthorityConfiguration::class)($this->maker, 'policies');
    }

    public function test_protected_quotation_and_reference_reads_do_not_reveal_another_owners_family(): void
    {
        $revision = $this->authorityDraft();
        Permission::findOrCreate(Duty::AUTHORITY_EVIDENCE_VIEW, 'web');
        $this->app->instance(SalesDutyManifest::class, app(SalesDutyManifest::class)->withActivated([Duty::AUTHORITY_EVIDENCE_VIEW]));
        $this->checker->givePermissionTo(Duty::AUTHORITY_EVIDENCE_VIEW);
        AccessScopeLimit::create(['user_id' => $this->checker->id, 'permission' => Duty::QUOTATION_VIEW, 'scope' => 'OWN']);
        Sanctum::actingAs($this->checker);
        $this->getJson('/api/v1/quotations/'.$revision->id.'/discount-authority')->assertNotFound();
        $this->getJson('/api/v1/quotations/'.$revision->id.'/discount-authority/history')->assertNotFound();
        $this->getJson('/api/v1/quotations/999999/discount-authority')->assertNotFound();
        $reference = DB::table('sales_price_reference_versions')->where('family_id', $revision->family_id)->value('id');
        $this->getJson('/api/v1/sales-discount-authority/references/'.$reference)->assertNotFound();
        $this->getJson('/api/v1/sales-discount-authority/reference-approvals')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_issued_evidence_is_read_from_its_pin_after_live_authority_changes(): void
    {
        $revision = $this->discount($this->authorityDraft(), '2');
        $policy = $this->authorityPolicy();
        $this->authorityAssignment($policy, 1);
        $this->authorityMode('ENFORCE');
        app(IssueQuotation::class)($this->checker, $revision->id, $revision->input_generation, $this->issueKey());
        $this->authorityMode('OFF');
        Permission::findOrCreate(Duty::AUTHORITY_EVIDENCE_VIEW, 'web');
        $this->app->instance(SalesDutyManifest::class, app(SalesDutyManifest::class)->withActivated([Duty::AUTHORITY_EVIDENCE_VIEW]));
        $this->maker->givePermissionTo(Duty::AUTHORITY_EVIDENCE_VIEW);
        $this->getJson('/api/v1/quotations/'.$revision->id.'/discount-authority')->assertOk()->assertJsonPath('data.mode', 'ENFORCE')
            ->assertJsonPath('data.current.result', 'ROUTINE')->assertJsonPath('data.current.reduction_base_amount', '4.0000');
    }

    public function test_an_undeclared_reduction_is_reflected_in_the_safe_approval_summary(): void
    {
        $revision = $this->authorityDraft();
        $line = $revision->lines()->where('membership', 'BASE')->firstOrFail();
        app(UpdateQuotationDraft::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey(), ['update_lines' => [['id' => $line->id, 'unit_price' => '99.0000']]]);
        $policy = $this->authorityPolicy();
        $this->authorityAssignment($policy, 1);
        $this->authorityMode('ENFORCE');
        $this->getJson('/api/v1/quotations/'.$revision->id.'/approval')->assertOk()->assertJsonPath('data.approval_needed', true)->assertJsonPath('data.can.request', true);
    }
}
