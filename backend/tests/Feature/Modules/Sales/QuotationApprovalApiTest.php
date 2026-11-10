<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Approvals\Models\ApprovalDecision;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalPolicyStatus;
use App\Modules\Sales\Actions\AssessQuotationIssueReadiness;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Models\QuotationIssue;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovalSubject;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

/**
 * Approvals integration (ADR 0158): a draft whose discount or final-net override needs approval can be sent for approval, decided by an independent approver and then issued; the
 * approval is bound to the exact draft, separation of duties is enforced, the record policy (OWN / ALL) is non-disclosing, retries replay, and the approved reductions stay frozen in
 * the issued record with the approval named.
 */
class QuotationApprovalApiTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        foreach ([Duty::QUOTATION_DISCOUNT, Duty::QUOTATION_PRICE_OVERRIDE, Duty::QUOTATION_APPROVE] as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $this->maker->givePermissionTo([Duty::QUOTATION_DISCOUNT, Duty::QUOTATION_PRICE_OVERRIDE]);
        $this->approver = User::factory()->create();
        $this->approver->givePermissionTo([Duty::QUOTATION_APPROVE, Duty::QUOTATION_VIEW]);
        $on = [Duty::QUOTATION_PREPARE, Duty::QUOTATION_VIEW, Duty::QUOTATION_ISSUE, Duty::QUOTATION_DISCOUNT, Duty::QUOTATION_PRICE_OVERRIDE, Duty::QUOTATION_APPROVE];
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest(array_map(fn (SalesDuty $d): SalesDuty => in_array($d->name, $on, true)
            ? new SalesDuty($d->name, $d->class, DutyStatus::Active, $d->operation, $d->projection, $d->deliveryGate, $d->scope) : $d, SalesDutyManifest::shipped()->all())));
        ApprovalSubjectSetting::query()->updateOrCreate(['subject_type' => QuotationApprovalSubject::CODE], ['mode' => ApprovalMode::Required]);
        $policy = ApprovalPolicy::query()->create(['subject_type' => QuotationApprovalSubject::CODE, 'code' => 'discounts', 'version_no' => 1, 'name' => 'Discount sign-off', 'priority' => 0,
            'status' => ApprovalPolicyStatus::Active, 'reason' => 'test', 'changed_by' => $this->checker->id]);
        $policy->steps()->create(['stage' => 1, 'name' => 'Sales approver', 'required_approvals' => 1]);
    }

    /** A prepared draft carrying a 20 percent discount on its first line (no limit is published, so it needs approval). */
    private function discounted(): QuotationRevision
    {
        $revision = $this->preparedDraft();
        Sanctum::actingAs($this->maker);
        $line = (int) $revision->lines()->where('membership', 'BASE')->value('id');
        $this->patchJson("/api/v1/quotations/{$revision->id}", ['expected_generation' => $revision->input_generation, 'update_lines' => [['id' => $line, 'discount' => ['kind' => 'PERCENT', 'value' => '20']]]],
            ['Idempotency-Key' => $this->issueKey()])->assertOk();

        return $revision->refresh();
    }

    private function generation(QuotationRevision $revision): int
    {
        return QuotationRevision::findOrFail($revision->id)->input_generation;
    }

    private function request(QuotationRevision $revision, ?string $key = null, ?int $generation = null): TestResponse
    {
        return $this->postJson("/api/v1/quotations/{$revision->id}/approval", ['expected_generation' => $generation ?? $this->generation($revision)], ['Idempotency-Key' => $key ?? $this->issueKey()]);
    }

    private function decide(QuotationRevision $revision, string $decision, ?string $reason = null, ?string $key = null, ?int $generation = null): TestResponse
    {
        return $this->postJson("/api/v1/quotations/{$revision->id}/approval/decision", ['expected_generation' => $generation ?? $this->generation($revision), 'decision' => $decision] + ($reason === null ? [] : ['reason' => $reason]),
            ['Idempotency-Key' => $key ?? $this->issueKey()]);
    }

    /** @return list<string> */
    private function blockers(QuotationRevision $revision): array
    {
        return array_map(fn (array $b): string => $b['code'], app(AssessQuotationIssueReadiness::class)($this->maker, $revision->id)['blockers']);
    }

    public function test_sales_approval_reasons_follow_the_shared_author_and_evidence_projection(): void
    {
        $revision = $this->discounted();
        $this->request($revision)->assertCreated();
        Sanctum::actingAs($this->approver);
        $this->decide($revision, 'REJECTED', 'Independent review reason')->assertCreated();
        $url = "/api/v1/quotations/{$revision->id}/approval";
        $this->getJson($url)->assertOk()->assertJsonPath('data.request.decisions.0.reason', 'Independent review reason')->assertJsonPath('data.request.closing_reason', 'Independent review reason');
        Sanctum::actingAs($this->maker);
        $this->getJson($url)->assertOk()->assertJsonPath('data.request.decisions.0.reason', null)->assertJsonPath('data.request.closing_reason', null);
        Permission::findOrCreate('approvals.reason.view', 'web');
        $this->maker->givePermissionTo('approvals.reason.view');
        $this->getJson($url)->assertOk()->assertJsonPath('data.request.decisions.0.reason', 'Independent review reason')->assertJsonPath('data.request.closing_reason', 'Independent review reason');
    }

    public function test_request_decide_and_issue_with_the_approval_named_on_the_issued_record(): void
    {
        $revision = $this->discounted();
        $this->assertContains('APPROVAL_REQUIRED', $this->blockers($revision));
        $this->assertSame('NOT_REQUESTED', $this->getJson("/api/v1/quotations/{$revision->id}/approval")->assertOk()->json('data.state'));
        $this->assertTrue($this->getJson("/api/v1/quotations/{$revision->id}/approval")->json('data.can.request'));

        $this->request($revision)->assertCreated()->assertJsonPath('data.status', 'PENDING');
        $this->assertContains('APPROVAL_PENDING', $this->blockers($revision));
        $this->assertNotContains('APPROVAL_REQUIRED', $this->blockers($revision));
        $this->getJson("/api/v1/quotations/{$revision->id}/approval")->assertJsonPath('data.state', 'PENDING')->assertJsonPath('data.can.decide', false)->assertJsonPath('data.can.withdraw', true);

        Sanctum::actingAs($this->approver);
        $this->getJson("/api/v1/quotations/{$revision->id}/approval")->assertOk()->assertJsonPath('data.can.decide', true)->assertJsonPath('data.can.request', false);
        $this->decide($revision, 'APPROVED')->assertCreated()->assertJsonPath('data.status', 'APPROVED');
        $this->getJson("/api/v1/quotations/{$revision->id}/approval")->assertJsonPath('data.state', 'APPROVED')->assertJsonPath('data.request.decisions.0.decision', 'APPROVED');
        $this->assertSame([], $this->blockers($revision));

        $outcome = app(IssueQuotation::class)($this->checker, $revision->id, $this->generation($revision), $this->issueKey());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $issue = QuotationIssue::findOrFail($outcome->issueId);
        $request = ApprovalRequest::query()->where('subject_id', $revision->id)->firstOrFail();
        $this->assertSame($request->id, $issue->approval_request_id);
        $this->assertSame($request->facts['facts']['content_fingerprint'], $issue->approval_fingerprint);
        $frozen = QuotationIssuedLine::query()->where('issue_id', $issue->id)->orderBy('sort_order')->firstOrFail();
        $this->assertSame(['DISCOUNT', '40.0000', '160.0000'], [$frozen->line_reduction_kind, (string) $frozen->line_reduction_amount, (string) $frozen->net], 'the approved discount is frozen into the issued terms');
        $this->assertStringNotContainsString('approval', strtolower(json_encode(QuotationIssuedLine::query()->where('issue_id', $issue->id)->get()->toArray())));
    }

    public function test_a_draft_that_needs_no_approval_cannot_ask_for_one(): void
    {
        $revision = $this->preparedDraft();
        Sanctum::actingAs($this->maker);
        $this->request($revision)->assertUnprocessable()->assertJsonPath('reason_code', 'APPROVAL_NOT_NEEDED');
    }

    public function test_an_unconfigured_company_is_never_read_as_approved(): void
    {
        $revision = $this->discounted();
        ApprovalSubjectSetting::query()->where('subject_type', QuotationApprovalSubject::CODE)->delete();
        $this->request($revision)->assertUnprocessable()->assertJsonPath('reason_code', 'APPROVAL_NOT_CONFIGURED');
        ApprovalSubjectSetting::query()->create(['subject_type' => QuotationApprovalSubject::CODE, 'mode' => ApprovalMode::Required]);
        ApprovalPolicy::query()->update(['status' => ApprovalPolicyStatus::Draft]);
        $this->request($revision)->assertUnprocessable()->assertJsonPath('reason_code', 'APPROVAL_NOT_CONFIGURED');
        $this->assertContains('APPROVAL_REQUIRED', $this->blockers($revision));
        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    public function test_the_preparer_can_never_approve_their_own_draft_even_holding_the_duty(): void
    {
        $revision = $this->discounted();
        $this->request($revision)->assertCreated();
        $this->maker->givePermissionTo(Duty::QUOTATION_APPROVE);
        $this->decide($revision, 'APPROVED')->assertUnprocessable()->assertJsonPath('reason_code', 'APPROVAL_SELF_APPROVAL');

        // A second preparer who requested it, and another who only entered a concession, are also excluded.
        $other = User::factory()->create();
        $other->givePermissionTo([Duty::QUOTATION_PREPARE, Duty::QUOTATION_VIEW, Duty::QUOTATION_APPROVE]);
        Sanctum::actingAs($other);
        $this->decide($revision, 'APPROVED')->assertCreated();
        $this->assertSame(1, ApprovalDecision::query()->count());
    }

    public function test_an_edit_after_the_request_ends_it_and_an_old_decision_is_refused(): void
    {
        $revision = $this->discounted();
        $this->request($revision)->assertCreated();
        $reviewed = $this->generation($revision);
        $line = (int) $revision->lines()->where('membership', 'BASE')->value('id');
        $this->patchJson("/api/v1/quotations/{$revision->id}", ['expected_generation' => $reviewed, 'update_lines' => [['id' => $line, 'discount' => ['kind' => 'PERCENT', 'value' => '25']]]], ['Idempotency-Key' => $this->issueKey()])->assertOk();
        $this->assertSame('INVALIDATED', ApprovalRequest::query()->firstOrFail()->status->value);

        Sanctum::actingAs($this->approver);
        $this->decide($revision, 'APPROVED', null, null, $reviewed)->assertUnprocessable()->assertJsonPath('reason_code', 'QUOTATION_DRAFT_STALE');
        $this->decide($revision, 'APPROVED')->assertUnprocessable()->assertJsonPath('reason_code', 'APPROVAL_NOT_PENDING');
        $this->assertSame(0, ApprovalDecision::query()->count());
    }

    public function test_an_approval_does_not_survive_a_change_to_the_approved_draft(): void
    {
        $revision = $this->discounted();
        $this->request($revision)->assertCreated();
        Sanctum::actingAs($this->approver);
        $this->decide($revision, 'APPROVED')->assertCreated();
        $this->assertSame([], $this->blockers($revision));

        Sanctum::actingAs($this->maker);
        $line = (int) $revision->lines()->where('membership', 'BASE')->value('id');
        $this->patchJson("/api/v1/quotations/{$revision->id}", ['expected_generation' => $this->generation($revision), 'update_lines' => [['id' => $line, 'discount' => ['kind' => 'PERCENT', 'value' => '35']]]], ['Idempotency-Key' => $this->issueKey()])->assertOk();
        $this->assertContains('APPROVAL_REQUIRED', $this->blockers($revision), 'the larger discount needs a fresh approval');
        $this->expectExceptionMessage('APPROVAL_REQUIRED');
        app(IssueQuotation::class)($this->checker, $revision->id, $this->generation($revision), $this->issueKey());
    }

    public function test_a_rejection_needs_a_reason_blocks_issue_and_cannot_be_asked_again_unchanged(): void
    {
        $revision = $this->discounted();
        $this->request($revision)->assertCreated();
        Sanctum::actingAs($this->approver);
        $this->decide($revision, 'REJECTED')->assertUnprocessable();
        $this->decide($revision, 'REJECTED', 'Too deep for this customer')->assertCreated()->assertJsonPath('data.status', 'REJECTED');
        $this->assertContains('APPROVAL_REJECTED', $this->blockers($revision));

        Sanctum::actingAs($this->maker);
        $this->getJson("/api/v1/quotations/{$revision->id}/approval")->assertJsonPath('data.state', 'REJECTED')->assertJsonPath('data.request.decisions.0.reason', null)->assertJsonPath('data.request.closing_reason', null);
        $this->request($revision)->assertUnprocessable()->assertJsonPath('reason_code', 'APPROVAL_REJECTED');
        $line = (int) $revision->lines()->where('membership', 'BASE')->value('id');
        $this->patchJson("/api/v1/quotations/{$revision->id}", ['expected_generation' => $this->generation($revision), 'update_lines' => [['id' => $line, 'discount' => ['kind' => 'PERCENT', 'value' => '15']]]], ['Idempotency-Key' => $this->issueKey()])->assertOk();
        $this->request($revision)->assertCreated();
    }

    public function test_the_requester_can_withdraw_and_no_one_else_can(): void
    {
        $revision = $this->discounted();
        $this->request($revision)->assertCreated();
        $withdraw = fn (string $reason = 'Customer changed the scope') => $this->postJson("/api/v1/quotations/{$revision->id}/approval/withdrawal", ['expected_generation' => $this->generation($revision), 'reason' => $reason], ['Idempotency-Key' => $this->issueKey()]);

        $other = User::factory()->create();
        $other->givePermissionTo([Duty::QUOTATION_PREPARE, Duty::QUOTATION_VIEW]);
        Sanctum::actingAs($other);
        $withdraw()->assertUnprocessable()->assertJsonPath('reason_code', 'APPROVAL_NOT_ELIGIBLE');
        Sanctum::actingAs($this->maker);
        $withdraw('')->assertUnprocessable();
        $withdraw()->assertCreated()->assertJsonPath('data.status', 'WITHDRAWN');
        $this->getJson("/api/v1/quotations/{$revision->id}/approval")->assertOk()->assertJsonPath('data.request.closing_reason', 'Customer changed the scope');
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/quotations/{$revision->id}/approval")->assertOk()->assertJsonPath('data.request.closing_reason', null);
        Sanctum::actingAs($this->maker);
        $this->assertContains('APPROVAL_REQUIRED', $this->blockers($revision));
        $this->request($revision)->assertCreated();
    }

    public function test_requests_and_decisions_replay_and_a_reused_key_is_a_conflict(): void
    {
        $revision = $this->discounted();
        $first = $this->request($revision, 'req-1')->assertCreated();
        $this->request($revision, 'req-1')->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.approval_request_id', $first->json('data.approval_request_id'));
        $this->postJson("/api/v1/quotations/{$revision->id}/approval", ['expected_generation' => $this->generation($revision) + 1], ['Idempotency-Key' => 'req-1'])->assertConflict()->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');
        $this->assertSame(1, ApprovalRequest::query()->count());

        Sanctum::actingAs($this->approver);
        $this->decide($revision, 'APPROVED', null, 'dec-1')->assertCreated();
        $this->decide($revision, 'APPROVED', null, 'dec-1')->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame(1, ApprovalDecision::query()->count());
    }

    public function test_the_record_policy_is_non_disclosing_and_the_generic_route_cannot_bypass_it(): void
    {
        $revision = $this->discounted();
        $this->request($revision)->assertCreated();

        $scoped = User::factory()->create();
        $scoped->givePermissionTo([Duty::QUOTATION_APPROVE, Duty::QUOTATION_VIEW]);
        foreach ([Duty::QUOTATION_APPROVE, Duty::QUOTATION_VIEW] as $duty) {
            AccessScopeLimit::create(['user_id' => $scoped->id, 'permission' => $duty, 'scope' => 'OWN']);
        }
        Sanctum::actingAs($scoped);
        $this->getJson("/api/v1/quotations/{$revision->id}/approval")->assertNotFound();
        $this->decide($revision, 'APPROVED')->assertNotFound();
        $request = ApprovalRequest::query()->firstOrFail();
        // The engine's own decision route and inbox apply the same record policy: a capability alone never reaches a record the family policy hides.
        $this->getJson('/api/v1/approval-requests/inbox')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/approval-requests/{$request->id}/decide", ['decision' => 'APPROVED'])->assertNotFound();
        $this->assertSame(0, ApprovalDecision::query()->count());

        $nobody = User::factory()->create();
        Sanctum::actingAs($nobody);
        $this->getJson("/api/v1/quotations/{$revision->id}/approval")->assertForbidden();
        $this->decide($revision, 'APPROVED')->assertForbidden();

        $maker = $this->maker;
        Sanctum::actingAs($maker);
        $this->decide($revision, 'APPROVED')->assertForbidden();
    }

    public function test_a_final_net_override_also_needs_and_receives_approval(): void
    {
        $revision = $this->preparedDraft();
        Sanctum::actingAs($this->maker);
        $line = (int) $revision->lines()->where('membership', 'BASE')->value('id');
        $this->patchJson("/api/v1/quotations/{$revision->id}", ['expected_generation' => $revision->input_generation, 'update_lines' => [['id' => $line, 'net_override' => ['amount' => '150', 'reason' => 'Loyalty']]]], ['Idempotency-Key' => $this->issueKey()])->assertOk();
        $this->assertContains('APPROVAL_REQUIRED', $this->blockers($revision));
        $this->request($revision)->assertCreated();
        $this->assertSame('OVERRIDE', ApprovalRequest::query()->firstOrFail()->facts['facts']['concessions']);
        Sanctum::actingAs($this->approver);
        $this->decide($revision, 'APPROVED')->assertCreated();
        $this->assertSame([], $this->blockers($revision));
    }

    private function linkMigration(): object
    {
        return require base_path('database/migrations/2026_11_20_000000_add_quotation_issue_approval_link.php');
    }

    public function test_the_issue_link_migration_removes_cleanly_when_empty_and_refuses_when_an_issue_names_an_approval(): void
    {
        $this->linkMigration()->down();
        $this->assertFalse(Schema::hasColumn('quotation_issues', 'approval_request_id'));
        $this->linkMigration()->up();
        $this->assertTrue(Schema::hasColumn('quotation_issues', 'approval_request_id'));

        $revision = $this->discounted();
        $this->request($revision)->assertCreated();
        Sanctum::actingAs($this->approver);
        $this->decide($revision, 'APPROVED')->assertCreated();
        app(IssueQuotation::class)($this->checker, $revision->id, $this->generation($revision), $this->issueKey());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('name the approval');
        $this->linkMigration()->down();
    }

    public function test_the_approval_link_on_an_issued_record_is_append_only(): void
    {
        $revision = $this->discounted();
        $this->request($revision)->assertCreated();
        Sanctum::actingAs($this->approver);
        $this->decide($revision, 'APPROVED')->assertCreated();
        $outcome = app(IssueQuotation::class)($this->checker, $revision->id, $this->generation($revision), $this->issueKey());
        $issue = QuotationIssue::findOrFail($outcome->issueId);
        $this->assertNotNull($issue->approval_request_id);
        $this->assertNotNull($issue->approval_fingerprint);
        $this->expectException(QueryException::class);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::table('quotation_issues')->where('id', $issue->id)->update(['approval_fingerprint' => null]);
    }
}
